# Dashboard API

## Delivery Order (Locked)
1. Settings + embed
2. Questions CRUD + reorder
3. Leads detail + patch
4. Conversations + transcript
5. Valuations
6. Analytics

## Settings + Embed

### GET `/app/settings`
Returns:
- `client { id, name }`
- `settings { bot_name, brand_color, accent_color, logo_url, prompt_settings, allowed_origins, widget_security_version }`

### PUT `/app/settings`
Allowed `settings` keys (others ignored for forward compatibility):
- `bot_name`
- `brand_color`
- `accent_color`
- `logo_url`
- `prompt_settings` (replace entire object when provided)

Can also update `client.name`.

Note:
- Domains are managed only via `PUT /app/settings/domains`.
- Permissions are sourced from `GET /app/auth/me`, not from `/app/settings`.

### GET `/app/embed-code`
Returns:
- `script_url`
- `params { client_id, widget_security_version }`
- `widget_security_version`
- `snippet`

## List Contract (Locked)
Every list endpoint must:
1. Return Laravel paginator JSON shape (`data`, `links`, `meta`).
2. Accept `page` and `per_page`.
3. Use `per_page=20` by default when omitted.
4. Keep deterministic default ordering per endpoint.
5. Ignore unsupported sort params and keep default ordering.
6. Include `meta.default_sort` in list responses (e.g. `created_at:desc`).

### Default Ordering
- leads: `created_at desc`
- catalog imports: `created_at desc`
- conversations: `last_activity_at desc`
- valuations: `created_at desc`
- appraisal questions: `order_index asc`

## Analytics

### Range Semantics (Locked)
- `today` = `00:00:00Z -> now`
- `7d` = `now-7d -> now`
- `30d` = `now-30d -> now`
- `all`:
  - summary: unbounded (`from = null`, `to = now`)
  - timeseries: capped to last 30 days (for bounded payload)

### GET `/app/analytics/summary`
Returns:
- `range`
- `client { id, name }`
- `from`
- `to`
- `conversations` (counted by `last_activity_at`)
- `valuations`
- `leads`
- `catalog_imports`

### GET `/app/analytics/timeseries`
Returns:
- `range`
- `client { id, name }`
- `from`
- `to`
- `data[]`

Timeseries locks:
- UTC day buckets (`YYYY-MM-DD`)
- ascending by `date`
- missing days included with zero counts
- conversations bucketed by `last_activity_at`

## Demo Data (Dev)

### Command
`php artisan demo:seed-dashboard --client=\"Acme Auctions\" [--reset]`

Behavior:
- `--reset`: clears demo data for the target client only (dev-only operation).
- without `--reset`: idempotent (no duplicate demo records).
