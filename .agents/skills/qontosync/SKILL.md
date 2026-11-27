---
name: qontosync
description: >-
  Use this skill when developing QontoSync, integrating Qonto API endpoints
  (auth, transactions, statements, webhooks, e-invoicing, OpenAPI schemas),
  checking Dolibarr core APIs/conventions, inspecting the EInvoicing module
  reference, or bootstrapping and refreshing local dev references in dev/.
---

# QontoSync Development & Local References

Local development references live under `dev/` at the `qontosync` repository root (gitignored and excluded from release zips by `dev/packager.sh`):

- `dev/docs-qonto/` — Local mirror of `https://docs.qonto.com/llms.txt` (complete per-endpoint OpenAPI 3.1.1 `.md` specs).
- `dev/dolibarr/` — Latest stable non-dev Dolibarr release from GitHub (also exposes official Dolibarr skills via `.agents/skills.json`).
- `dev/einvoicing/` — Latest `einvoicing` module from `Dolibarr/dolibarr-community-modules`.

## Bootstrap or Refresh References

Before relying on local references in a task (or after a fresh `git clone`), run from the `qontosync/` repository root:

```bash
python3 dev/sync-refs.py --if-older-than 7d
```

- When all three references exist locally and are less than 7 days old, this check reads only local metadata (`METADATA.json` / `.sync-meta.json`) and exits immediately with zero network calls.
- When any reference (`dev/docs-qonto/INDEX.md`, `dev/dolibarr/`, or `dev/einvoicing/`) is missing or older than 7 days, it automatically downloads missing references or checks for newer releases.

Manual / targeted options:
- Check and update immediately regardless of age: `python3 dev/sync-refs.py`
- Sync only specific targets: `python3 dev/sync-refs.py --only qonto`, `--only dolibarr,einvoicing`, etc.
- Pin a Dolibarr tag if needed: `python3 dev/sync-refs.py --only dolibarr --dolibarr-version 24.0.4`.
- Force re-download: add `--force`.

Note: After bootstrapping `dev/dolibarr/` on a fresh clone, start a new conversation if you need `.agents/skills.json` to load the newly downloaded Dolibarr skills (`skill-doli-devmodule`, `skill-doli-dev`, `skill-doli-code-review`, `skill-doli-translation`, `skill-doli-test-*`).

## How to Use Each Reference

1. **Qonto API Documentation (`dev/docs-qonto/`)**:
   - Consult `dev/docs-qonto/` first before searching the web.
   - Start by searching or reading `dev/docs-qonto/INDEX.md` (local relative links) or `dev/docs-qonto/llms.txt`.
   - Open the individual `.md` files (`dev/docs-qonto/api-reference/business-api/...`, `dev/docs-qonto/guides/...`, `dev/docs-qonto/ai-instructions.md`) for full OpenAPI 3.1.1 schemas and examples.
2. **Dolibarr Core Reference (`dev/dolibarr/`)**:
   - Inspect `dev/dolibarr/htdocs/` for core classes, bank/invoice/payment APIs, hooks, and Dolibarr module patterns matching the latest stable release.
3. **EInvoicing Reference (`dev/einvoicing/`)**:
   - Inspect `dev/einvoicing/` and `dev/einvoicing/doc/` (such as `ADD-A-PDP-PROVIDER.md`, `LIFECYCLE-STATUSES.md`, and `COVERAGE-AND-LIMITS.md`) for PDP/PA e-invoicing workflows and Factur-X integration patterns.
