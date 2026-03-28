# Docker Self-Hosted Setup

This project now supports a self-hosted Docker stack (WordPress + MySQL + n8n).

## Start

```bash
docker compose up -d
```

Services:
- WordPress: http://localhost:8080
- n8n: http://localhost:5678

## WordPress plugin path

The repository `plugins/` directory is mounted in container under:

`/var/www/html/wp-content/plugins`

You can symlink or copy plugin folders from there to active plugin paths in WordPress.

## n8n mode in PedagoLens

In WordPress admin:
1. Open API Bridge settings.
2. Set IA mode to `n8n`.
3. Fill:
   - `Webhook URL`
   - `API Key` (optional)
   - `Timeout`

Expected n8n response payload (JSON):
- either direct schema fields, for example:
  - `{"reply":"...","guardrail_triggered":false,"follow_up_questions":[...]}`
- or wrapped in data:
  - `{"success":true,"data":{...schema fields...}}`

The schema must match the requested `prompt_key` expected by API Bridge.

## Migration plugin

Activate `PedagoLens Migration` and run it from:
- `Tools > PedagoLens Migration`

It will:
- delete all existing pages
- recreate all PedagoLens pages and shortcodes
- set homepage + permalink structure
- import media from `screenshots/` and `exemple/stitch/`
