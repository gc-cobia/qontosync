---
name: qonto-api
description: >-
  Use the local Qonto API documentation mirror in dev/docs-qonto/ whenever
  working on Qonto API integrations, endpoints, authentication, webhooks,
  e-invoicing, or OpenAPI schemas in QontoSync.
---

# Qonto API Local Documentation

A local mirror of the official Qonto developer documentation (`https://docs.qonto.com/llms.txt`) lives in `dev/docs-qonto/` at the `qontosync` repository root.

## Bootstrap or Refresh

Only `dev/docs-qonto/sync.py` is tracked in Git; the mirrored Markdown files are gitignored.

- On a fresh clone (if `dev/docs-qonto/INDEX.md` does not exist) or whenever you need updated Qonto docs, run from the `qontosync/` repository root:

```bash
python3 dev/docs-qonto/sync.py
```

## How to Use

1. **Primary reference**: Always consult `dev/docs-qonto/` first before searching the web when working on Qonto endpoints, OAuth/API key auth, PSD2/SCA, webhooks, Factur-X/e-invoicing, or payload schemas.
2. **Discover pages**: Search or read `dev/docs-qonto/INDEX.md` (uses local relative paths) or `dev/docs-qonto/llms.txt`.
3. **Read full OpenAPI specs**: Open the corresponding `.md` file directly (for example `dev/docs-qonto/api-reference/business-api/...`, `dev/docs-qonto/guides/...`, `dev/docs-qonto/ai-instructions.md`). Individual `.md` files contain complete OpenAPI 3.1.1 schemas and examples, whereas `llms-full.txt` online truncates API endpoint pages.
