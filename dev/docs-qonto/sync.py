#!/usr/bin/env python3
"""Mirror Qonto developer documentation locally from https://docs.qonto.com/llms.txt."""

from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
import json
from pathlib import Path, PurePosixPath
import re
import sys
import time
import urllib.error
import urllib.request

BASE_URL = "https://docs.qonto.com/"
LLMS_TXT_URL = f"{BASE_URL}llms.txt"
USER_AGENT = "qontosync-docs-sync/1.0"
MAX_WORKERS = 12
MAX_RETRIES = 4
REQUEST_TIMEOUT = 30
LINK_RE = re.compile(r"\((https://docs\.qonto\.com/([^)\s]+\.md))\)")
RETRYABLE_HTTP_CODES = {429, 500, 502, 503, 504}


def fetch_bytes(url: str) -> bytes:
    """Fetch a URL with retries and exponential backoff."""
    last_err: Exception | None = None
    for attempt in range(MAX_RETRIES + 1):
        req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
        try:
            with urllib.request.urlopen(req, timeout=REQUEST_TIMEOUT) as resp:
                return resp.read()
        except urllib.error.HTTPError as err:
            last_err = err
            if err.code not in RETRYABLE_HTTP_CODES or attempt == MAX_RETRIES:
                raise
            retry_after = err.headers.get("Retry-After") if err.headers else None
            if retry_after and retry_after.isdigit():
                delay = max(1.0, float(retry_after))
            else:
                delay = 1.5 * (2**attempt)
            time.sleep(delay)
        except (urllib.error.URLError, TimeoutError, OSError) as err:
            last_err = err
            if attempt == MAX_RETRIES:
                raise
            time.sleep(1.5 * (2**attempt))
    assert last_err is not None
    raise last_err


def validate_rel_path(rel_str: str, base_dir: Path) -> Path:
    """Validate that a relative POSIX path stays strictly inside base_dir."""
    posix = PurePosixPath(rel_str)
    if posix.is_absolute() or ".." in posix.parts or not rel_str or rel_str.startswith("/"):
        raise ValueError(f"Unsafe relative path: {rel_str!r}")
    target = (base_dir / Path(*posix.parts)).resolve()
    base_resolved = base_dir.resolve()
    if not target.is_relative_to(base_resolved) or target == base_resolved:
        raise ValueError(f"Path escapes destination directory: {rel_str!r}")
    return target


def download_page(url: str, rel_str: str, base_dir: Path) -> tuple[str, int, str | None]:
    """Download one markdown page and write it under base_dir."""
    try:
        target = validate_rel_path(rel_str, base_dir)
        data = fetch_bytes(url)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        return rel_str, len(data), None
    except Exception as err:  # noqa: BLE001
        return rel_str, 0, f"{type(err).__name__}: {err}"


def prune_stale_files(base_dir: Path, expected_rels: set[str]) -> int:
    """Remove stale .md files under base_dir not present in expected_rels (keeps INDEX.md)."""
    removed = 0
    for md_file in sorted(base_dir.rglob("*.md")):
        rel_posix = md_file.relative_to(base_dir).as_posix()
        if rel_posix == "INDEX.md":
            continue
        if rel_posix not in expected_rels:
            md_file.unlink()
            removed += 1

    # Remove empty directories bottom-up
    for directory in sorted((p for p in base_dir.rglob("*") if p.is_dir()), reverse=True):
        try:
            directory.rmdir()
        except OSError:
            pass
    return removed


def build_index_md(llms_text: str, synced_at: str, total_urls: int) -> str:
    """Create local INDEX.md from llms.txt with rewritten relative links."""
    header = (
        f"<!-- Synced from {LLMS_TXT_URL} at {synced_at} ({total_urls} pages). "
        f"Refresh with: python3 dev/docs-qonto/sync.py -->\n\n"
    )
    rewritten = LINK_RE.sub(lambda m: f"({m.group(2)})", llms_text)
    return header + rewritten


def main() -> int:
    started = time.monotonic()
    base_dir = Path(__file__).resolve().parent
    base_dir.mkdir(parents=True, exist_ok=True)

    llms_bytes = fetch_bytes(LLMS_TXT_URL)
    llms_text = llms_bytes.decode("utf-8")
    (base_dir / "llms.txt").write_text(llms_text, encoding="utf-8")

    # Preserve order while deduplicating URLs
    targets: dict[str, str] = {}
    for match in LINK_RE.finditer(llms_text):
        full_url, rel_path = match.group(1), match.group(2)
        targets.setdefault(rel_path, full_url)

    synced_at = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    downloaded = 0
    total_bytes = 0
    failed: list[dict[str, str]] = []

    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as pool:
        futures = {
            pool.submit(download_page, url, rel_path, base_dir): (rel_path, url)
            for rel_path, url in targets.items()
        }
        for future in as_completed(futures):
            rel_path, url = futures[future]
            _, size, error = future.result()
            if error is None:
                downloaded += 1
                total_bytes += size
            else:
                failed.append({"url": url, "path": rel_path, "error": error})

    failed.sort(key=lambda item: item["url"])

    index_md = build_index_md(llms_text, synced_at, len(targets))
    (base_dir / "INDEX.md").write_text(index_md, encoding="utf-8")

    if not failed:
        prune_stale_files(base_dir, set(targets.keys()))

    metadata = {
        "source": LLMS_TXT_URL,
        "synced_at_utc": synced_at,
        "total_urls": len(targets),
        "downloaded": downloaded,
        "failed": failed,
        "total_bytes": total_bytes,
    }
    (base_dir / "METADATA.json").write_text(
        json.dumps(metadata, indent=2) + "\n", encoding="utf-8"
    )

    elapsed = time.monotonic() - started
    size_mb = total_bytes / (1024 * 1024)
    print(
        f"Synced {downloaded}/{len(targets)} pages, failed={len(failed)}, "
        f"size={size_mb:.2f} MB in {elapsed:.1f}s"
    )
    return 0 if not failed else 1


if __name__ == "__main__":
    sys.exit(main())
