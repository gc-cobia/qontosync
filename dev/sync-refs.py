#!/usr/bin/env python3
"""Synchronize local development references (Qonto docs, latest stable Dolibarr, EInvoicing)."""

from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.error
import urllib.request

DOLIBARR_REPO = "Dolibarr/dolibarr"
COMMUNITY_REPO = "Dolibarr/dolibarr-community-modules"
STABLE_TAG_RE = re.compile(r"^(\d+)\.(\d+)\.(\d+)$")
DURATION_RE = re.compile(r"^(\d+)([dh]?)$")
USER_AGENT = "qontosync-refs-sync/1.0"
RETRYABLE_HTTP_CODES = {429, 500, 502, 503, 504}
MAX_RETRIES = 3


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def github_headers(accept: str = "application/vnd.github+json") -> dict[str, str]:
    headers = {"User-Agent": USER_AGENT, "Accept": accept}
    token = os.environ.get("GITHUB_TOKEN")
    if token:
        headers["Authorization"] = f"Bearer {token}"
    return headers


def urlopen_with_retry(req: urllib.request.Request, timeout: int = 30):
    last_err: Exception | None = None
    for attempt in range(MAX_RETRIES + 1):
        try:
            return urllib.request.urlopen(req, timeout=timeout)
        except urllib.error.HTTPError as err:
            last_err = err
            if err.code not in RETRYABLE_HTTP_CODES or attempt == MAX_RETRIES:
                raise
            retry_after = err.headers.get("Retry-After") if err.headers else None
            delay = max(1.0, float(retry_after)) if (retry_after and retry_after.isdigit()) else 2.0 * (2**attempt)
            time.sleep(delay)
        except (urllib.error.URLError, TimeoutError, OSError) as err:
            last_err = err
            if attempt == MAX_RETRIES:
                raise
            time.sleep(2.0 * (2**attempt))
    assert last_err is not None
    raise last_err


def fetch_json(url: str):
    req = urllib.request.Request(url, headers=github_headers())
    with urlopen_with_retry(req, timeout=30) as resp:
        return json.loads(resp.read().decode("utf-8"))


def download_file(url: str, dest: Path) -> None:
    req = urllib.request.Request(url, headers=github_headers("application/octet-stream"))
    with urlopen_with_retry(req, timeout=120) as resp, dest.open("wb") as out:
        shutil.copyfileobj(resp, out, length=1024 * 256)


def resolve_latest_dolibarr_version() -> str:
    """Resolve the highest non-dev stable Dolibarr release tag (X.Y.Z)."""
    releases = fetch_json(f"https://api.github.com/repos/{DOLIBARR_REPO}/releases?per_page=30")
    candidates: list[tuple[tuple[int, int, int], str]] = []
    for rel in releases:
        if rel.get("draft") or rel.get("prerelease"):
            continue
        tag = str(rel.get("tag_name") or "")
        match = STABLE_TAG_RE.match(tag)
        if not match:
            continue
        version_tuple = (int(match.group(1)), int(match.group(2)), int(match.group(3)))
        candidates.append((version_tuple, tag))

    if not candidates:
        raise RuntimeError(f"No stable X.Y.Z release found for {DOLIBARR_REPO}")
    candidates.sort(key=lambda item: item[0], reverse=True)
    return candidates[0][1]


def _is_safe_rel_posix(rel_path: str) -> bool:
    if not rel_path or rel_path.startswith("/"):
        return False
    posix = PurePosixPath(rel_path)
    return ".." not in posix.parts and posix.parts != (".",)


def _is_safe_link_target(rel_member: str, linkname: str, is_hardlink: bool, strip_subpath: str | None) -> str | None:
    """Validate symlink/hardlink target remains within extraction root; return adjusted hardlink name."""
    if linkname.startswith("/"):
        return None
    if is_hardlink:
        parts = PurePosixPath(linkname).parts
        if len(parts) < 2 or ".." in parts:
            return None
        stripped = "/".join(parts[1:])
        if strip_subpath:
            prefix = f"{strip_subpath.rstrip('/')}/"
            if not stripped.startswith(prefix):
                return None
            stripped = stripped[len(prefix):]
        return stripped if _is_safe_rel_posix(stripped) else None

    parent = PurePosixPath(rel_member).parent
    joined = PurePosixPath(os.path.normpath(str(parent / linkname)))
    if joined.is_absolute() or ".." in joined.parts:
        return None
    return linkname


def safe_extract_tar(tar_path: Path, staging_dir: Path, strip_subpath: str | None = None) -> int:
    """Safely extract a GitHub tarball into staging_dir, stripping the top directory (and optional subpath)."""
    staging_resolved = staging_dir.resolve()
    extracted = 0
    sub_prefix = f"{strip_subpath.strip('/')}/" if strip_subpath else ""

    with tarfile.open(tar_path, "r:gz") as tar:
        for member in tar:
            raw_parts = PurePosixPath(member.name).parts
            if len(raw_parts) < 2 or ".." in raw_parts or raw_parts[0].startswith("/"):
                continue

            rel_after_top = "/".join(raw_parts[1:])
            if sub_prefix:
                if not rel_after_top.startswith(sub_prefix):
                    continue
                rel_path = rel_after_top[len(sub_prefix):]
            else:
                rel_path = rel_after_top

            if not _is_safe_rel_posix(rel_path):
                continue

            dest_path = (staging_dir / PurePosixPath(rel_path)).resolve()
            if not dest_path.is_relative_to(staging_resolved) or dest_path == staging_resolved:
                raise ValueError(f"Unsafe tar member path: {member.name!r}")

            if member.isdir():
                dest_path.mkdir(parents=True, exist_ok=True)
                extracted += 1
            elif member.isreg():
                dest_path.parent.mkdir(parents=True, exist_ok=True)
                src = tar.extractfile(member)
                if src is None:
                    continue
                with src, dest_path.open("wb") as out:
                    shutil.copyfileobj(src, out, length=1024 * 128)
                if member.mode & 0o111:
                    dest_path.chmod(0o755)
                else:
                    dest_path.chmod(0o644)
                extracted += 1
            elif member.issym():
                safe_target = _is_safe_link_target(rel_path, member.linkname, False, strip_subpath)
                if safe_target is None:
                    continue
                dest_path.parent.mkdir(parents=True, exist_ok=True)
                if dest_path.exists() or dest_path.is_symlink():
                    dest_path.unlink()
                os.symlink(safe_target, dest_path)
                extracted += 1
            elif member.islnk():
                safe_link = _is_safe_link_target(rel_path, member.linkname, True, strip_subpath)
                if safe_link is None:
                    continue
                link_src = (staging_dir / PurePosixPath(safe_link)).resolve()
                if not link_src.is_relative_to(staging_resolved) or not link_src.exists():
                    continue
                dest_path.parent.mkdir(parents=True, exist_ok=True)
                if dest_path.exists() or dest_path.is_symlink():
                    dest_path.unlink()
                os.link(link_src, dest_path)
                extracted += 1

    return extracted


def atomic_replace_dir(staging_dir: Path, target_dir: Path, dev_dir: Path, label: str) -> None:
    """Replace target_dir with staging_dir cleanly."""
    backup_dir: Path | None = None
    try:
        if target_dir.exists():
            backup_dir = Path(tempfile.mkdtemp(prefix=f".tmp-old-{label}-", dir=dev_dir))
            shutil.rmtree(backup_dir)
            target_dir.rename(backup_dir)
        staging_dir.rename(target_dir)
    except Exception:
        if backup_dir is not None and backup_dir.exists() and not target_dir.exists():
            backup_dir.rename(target_dir)
        raise
    finally:
        if backup_dir is not None and backup_dir.exists():
            shutil.rmtree(backup_dir, ignore_errors=True)


def parse_max_age(spec: str) -> timedelta:
    """Parse durations like '7d', '24h', or plain integer days ('7')."""
    match = DURATION_RE.match(spec.strip().lower())
    if not match:
        raise argparse.ArgumentTypeError(
            f"Invalid duration {spec!r}. Expected '<N>d', '<N>h', or integer days."
        )
    value = int(match.group(1))
    unit = match.group(2) or "d"
    return timedelta(hours=value) if unit == "h" else timedelta(days=value)


def read_json_file(path: Path) -> dict:
    if not path.is_file():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:  # noqa: BLE001
        return {}


def read_meta(target_dir: Path) -> dict:
    return read_json_file(target_dir / ".sync-meta.json")


def write_meta(target_dir: Path, data: dict) -> None:
    (target_dir / ".sync-meta.json").write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")


def freshness_age(meta: dict, required_path: Path) -> timedelta | None:
    """Return age since checked_at_utc/synced_at_utc if required_path exists, else None."""
    if not required_path.exists():
        return None
    for key in ("checked_at_utc", "synced_at_utc"):
        raw = meta.get(key)
        if not isinstance(raw, str):
            continue
        try:
            ts = datetime.strptime(raw, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
            return datetime.now(timezone.utc) - ts
        except ValueError:
            continue
    return None


def format_age(age: timedelta) -> str:
    hours = age.total_seconds() / 3600.0
    if hours >= 48:
        return f"{hours / 24.0:.1f}d"
    return f"{hours:.1f}h"


def sync_dolibarr(
    dev_dir: Path,
    version_arg: str,
    force: bool,
    max_age: timedelta | None = None,
) -> None:
    target_dir = dev_dir / "dolibarr"
    meta = read_meta(target_dir)
    if max_age is not None and not force and (version_arg == "latest" or meta.get("version") == version_arg):
        age = freshness_age(meta, target_dir / ".agents" / "skills")
        if age is not None and age < max_age:
            print(f"[dolibarr] Fresh ({meta.get('version', '?')}, age {format_age(age)}), skipping")
            return

    tag = resolve_latest_dolibarr_version() if version_arg == "latest" else version_arg
    if not force and meta.get("version") == tag and (target_dir / ".agents" / "skills").is_dir():
        meta["checked_at_utc"] = utc_now()
        write_meta(target_dir, meta)
        print(f"[dolibarr] Already up to date ({tag})")
        return

    tarball_url = f"https://codeload.github.com/{DOLIBARR_REPO}/tar.gz/refs/tags/{tag}"
    print(f"[dolibarr] Downloading {tag} from {tarball_url}...")
    tar_fd, tar_path_str = tempfile.mkstemp(prefix=".tmp-dolibarr-", suffix=".tar.gz", dir=dev_dir)
    os.close(tar_fd)
    tar_path = Path(tar_path_str)
    staging_dir = Path(tempfile.mkdtemp(prefix=".tmp-dolibarr-", dir=dev_dir))

    try:
        download_file(tarball_url, tar_path)
        count = safe_extract_tar(tar_path, staging_dir)
        if not (staging_dir / "htdocs" / "main.inc.php").is_file():
            raise RuntimeError(f"Extracted Dolibarr {tag} appears incomplete (missing htdocs/main.inc.php)")
        atomic_replace_dir(staging_dir, target_dir, dev_dir, "dolibarr")
        now = utc_now()
        write_meta(
            target_dir,
            {
                "repo": DOLIBARR_REPO,
                "version": tag,
                "tarball_url": tarball_url,
                "synced_at_utc": now,
                "checked_at_utc": now,
            },
        )
        print(f"[dolibarr] Synced {tag} ({count} entries)")
    finally:
        tar_path.unlink(missing_ok=True)
        if staging_dir.exists():
            shutil.rmtree(staging_dir, ignore_errors=True)


def sync_einvoicing(
    dev_dir: Path,
    force: bool,
    max_age: timedelta | None = None,
) -> None:
    target_dir = dev_dir / "einvoicing"
    meta = read_meta(target_dir)
    if max_age is not None and not force:
        age = freshness_age(meta, target_dir / "README.md")
        if age is not None and age < max_age:
            commit_short = str(meta.get("commit", "?"))[:10]
            print(f"[einvoicing] Fresh ({commit_short}, age {format_age(age)}), skipping")
            return

    commits = fetch_json(
        f"https://api.github.com/repos/{COMMUNITY_REPO}/commits?path=einvoicing&per_page=1"
    )
    if not commits:
        raise RuntimeError(f"No commits returned for einvoicing in {COMMUNITY_REPO}")
    sha = commits[0]["sha"]
    commit_date = commits[0]["commit"]["committer"]["date"]

    if not force and meta.get("commit") == sha and (target_dir / "README.md").is_file():
        meta["checked_at_utc"] = utc_now()
        write_meta(target_dir, meta)
        print(f"[einvoicing] Already up to date ({sha[:10]})")
        return

    tarball_url = f"https://codeload.github.com/{COMMUNITY_REPO}/tar.gz/refs/heads/main"
    print(f"[einvoicing] Downloading main ({sha[:10]}) from {tarball_url}...")
    tar_fd, tar_path_str = tempfile.mkstemp(prefix=".tmp-einvoicing-", suffix=".tar.gz", dir=dev_dir)
    os.close(tar_fd)
    tar_path = Path(tar_path_str)
    staging_dir = Path(tempfile.mkdtemp(prefix=".tmp-einvoicing-", dir=dev_dir))

    try:
        download_file(tarball_url, tar_path)
        count = safe_extract_tar(tar_path, staging_dir, strip_subpath="einvoicing")
        if not (staging_dir / "README.md").is_file():
            raise RuntimeError("Extracted einvoicing directory appears incomplete (missing README.md)")
        atomic_replace_dir(staging_dir, target_dir, dev_dir, "einvoicing")
        now = utc_now()
        write_meta(
            target_dir,
            {
                "repo": COMMUNITY_REPO,
                "branch": "main",
                "commit": sha,
                "commit_date": commit_date,
                "synced_at_utc": now,
                "checked_at_utc": now,
            },
        )
        print(f"[einvoicing] Synced {sha[:10]} ({count} entries)")
    finally:
        tar_path.unlink(missing_ok=True)
        if staging_dir.exists():
            shutil.rmtree(staging_dir, ignore_errors=True)


def sync_qonto(
    dev_dir: Path,
    force: bool = False,
    max_age: timedelta | None = None,
) -> None:
    if max_age is not None and not force:
        docs_dir = dev_dir / "docs-qonto"
        meta = read_json_file(docs_dir / "METADATA.json")
        age = freshness_age(meta, docs_dir / "INDEX.md")
        if age is not None and age < max_age:
            print(f"[qonto] Fresh ({meta.get('downloaded', '?')} pages, age {format_age(age)}), skipping")
            return

    print("[qonto] Syncing Qonto API documentation...")
    subprocess.run([sys.executable, str(dev_dir / "docs-qonto" / "sync.py")], check=True)


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--only",
        default="qonto,dolibarr,einvoicing",
        help="Comma-separated subset of targets to sync: qonto,dolibarr,einvoicing",
    )
    parser.add_argument(
        "--dolibarr-version",
        default="latest",
        help="Dolibarr tag to fetch (default: 'latest' stable X.Y.Z release)",
    )
    parser.add_argument(
        "--if-older-than",
        default=None,
        type=parse_max_age,
        help="Skip targets whose local metadata is newer than this duration (e.g. 7d, 24h)",
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="Re-download Dolibarr/EInvoicing even if local metadata already matches remote",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    valid_targets = {"qonto", "dolibarr", "einvoicing"}
    selected = [item.strip().lower() for item in args.only.split(",") if item.strip()]
    unknown = [item for item in selected if item not in valid_targets]
    if unknown or not selected:
        print(f"Invalid --only value: {args.only!r}. Choose from qonto, dolibarr, einvoicing.", file=sys.stderr)
        return 2

    dev_dir = Path(__file__).resolve().parent
    if "qonto" in selected:
        sync_qonto(dev_dir, args.force, args.if_older_than)
    if "dolibarr" in selected:
        sync_dolibarr(dev_dir, args.dolibarr_version, args.force, args.if_older_than)
    if "einvoicing" in selected:
        sync_einvoicing(dev_dir, args.force, args.if_older_than)
    return 0


if __name__ == "__main__":
    sys.exit(main())
