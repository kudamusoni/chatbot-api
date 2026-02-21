# Dashboard API Implementation and Data Flow

## Purpose
This document explains how the `/app/*` dashboard backend works: auth model, tenant context, role enforcement, endpoint groups, list contracts, analytics behavior, and demo data flow.

## High-level Architecture
Dashboard APIs are session-based JSON APIs under `routes/web.php`:

1. Session auth (`/app/auth/*`) establishes user context.
2. Active client context is stored in session (`active_client_id`).
3. `set.current.client` middleware binds `CurrentClient` for tenant scoping.
4. Controllers query tenant-scoped projections (`leads`, `conversations`, `valuations`, `catalog_imports`, etc.).
5. Presenter/support classes enforce stable response contracts.

## Middleware and Security Model

### Middleware stack
- `force.json.app`: ensures `/app/*` responds as JSON
- `app.auth`: session auth gate for app APIs
- `set.current.client`: binds `CurrentClient` and blocks when absent
- `require.tenant.role`: write-route role gate (owner/admin)

### Role model
- Tenant roles on `client_user`: `owner|admin|viewer`
- Platform roles on users: `none|support_admin|super_admin`
- `Gate::before` behavior:
  - `super_admin`: full access
  - `support_admin`: read-only ability allowlist (`viewAny`, `view`, `export`, `export_readonly`)

## API Surfaces

### 1) Auth + client context
- `POST /app/auth/login`
- `POST /app/auth/logout`
- `GET /app/auth/me`
- `GET /app/clients`
- `POST /app/clients/{client}/switch`
- `POST /app/clients/clear`

`/app/auth/me` is the UI boot payload (user + active client + permission flags).

### 2) Settings + embed
- `GET /app/settings`
- `PUT /app/settings` (owner/admin)
- `PUT /app/settings/domains` (owner/admin)
- `GET /app/embed-code`

Domain updates normalize + dedupe origins and atomically bump `widget_security_version`.

### 3) Appraisal questions
- `GET /app/appraisal-questions`
- `POST /app/appraisal-questions` (owner/admin)
- `PUT /app/appraisal-questions/{id}` (owner/admin)
- `DELETE /app/appraisal-questions/{id}` (owner/admin, deactivates)
- `PUT /app/appraisal-questions/reorder` (owner/admin)

Contract locks:
- 1-based `order_index`
- reorder applies to active questions only
- presenter maps DB schema to stable API keys and formats timestamps UTC `Z`

### 4) Leads
- `GET /app/leads`
- `GET /app/leads/{id}`
- `PATCH /app/leads/{id}` (owner/admin)
- `GET /app/leads/export`

PII policy lock:
- support_admin: masked PII
- super_admin + tenant members: full PII
- centralized in `LeadPresenter` to avoid policy drift

### 5) Conversations
- `GET /app/conversations`
- `GET /app/conversations/{id}/messages`
- `GET /app/conversations/{id}/events` (platform-only)

Message ordering lock: `event_id ASC`.

### 6) Valuations
- `GET /app/valuations`
- `GET /app/valuations/{id}`

Contracts:
- money fields are integer minor units
- `currency` always included
- `confidence` normalized to `0..1`

### 7) Catalog imports
- `GET /app/catalog-imports`
- `POST /app/catalog-imports` (owner/admin)
- `GET /app/catalog-imports/{id}`
- `POST /app/catalog-imports/{id}/upload` (owner/admin)
- `POST /app/catalog-imports/{id}/validate` (owner/admin)
- `POST /app/catalog-imports/{id}/start` (owner/admin)
- `POST /app/catalog-imports/{id}/retry` (owner/admin)
- `GET /app/catalog-imports/{id}/errors`

Contract locks include reason codes, streaming CSV error output, and deterministic import state handling.

### 8) Analytics (Phase 4)
- `GET /app/analytics/summary`
- `GET /app/analytics/timeseries`

Locks:
- `today = 00:00:00Z -> now`
- conversation metrics use `last_activity_at`
- timeseries uses UTC day buckets, ascending date, zero-filled missing days
- responses include `{ range, client, from, to, ... }`

## List Contract (Shared)
All list endpoints use shared defaults utility:
- Laravel paginator JSON (`data`, `links`, `meta`)
- accepts `page`, `per_page`
- default `per_page=20`
- deterministic default sort per endpoint
- unsupported sort params ignored
- `meta.default_sort` returned

Implementation helper:
- `app/Support/DashboardListDefaults.php`
- `config/dashboard.php`

## Range Contract (Shared)
Range parsing is centralized:
- `app/Support/DashboardRange.php`
- accepted: `today|7d|30d|all`
- invalid range => `422`
- UTC bounds always

## Error Contract
App errors are JSON and reason-coded where applicable:
- `401` unauthenticated
- `403` forbidden/role/member failures
- `409` conflict states (e.g., no active client)
- `422` validation errors
- CSRF mismatch for `/app/*` returns JSON `CSRF_INVALID`

## Auditing
Key actions log audit rows via `AuditLogger`:
- client switching
- settings updates
- domains updates
- lead updates
- import lifecycle actions

Audit data supports actor/client tracing and before/after payloads.

## Demo Data Workflow (Phase 4)

### Seeder
- `database/seeders/DashboardDemoSeeder.php`
- creates realistic client-scoped data across last 30 days for UI work

### Command
- `php artisan demo:seed-dashboard --client="Acme Auctions" [--reset]`

Behavior locks:
- `--reset`: clears demo data for target client only (dev-safe)
- no `--reset`: idempotent (no duplicate demo records)

## Data Flow Example: Dashboard Home
1. Frontend calls `GET /app/auth/me` for user + active client + permissions.
2. Frontend calls `GET /app/analytics/summary?range=7d`.
3. Frontend optionally calls `GET /app/analytics/timeseries?range=30d`.
4. UI renders counts/charts using provided `range/from/to/client` context directly.

## File Map (Main Touchpoints)
- Routes: `routes/web.php`
- App controllers: `app/Http/Controllers/App/*`
- Middleware: `app/Http/Middleware/*`
- Policies/provider: `app/Policies/*`, `app/Providers/AuthServiceProvider.php`
- Support/presenters: `app/Support/*`
- Requests: `app/Http/Requests/App/*`
- Seeders: `database/seeders/*`
- Console command: `routes/console.php`
- Contract docs: `docs/dashboard-api.md`

## Getting Started (curl Flows)

These examples use cookie-based session auth for `/app/*`.

### 1) Get CSRF cookie
```bash
curl -i -c cookies.txt http://localhost/sanctum/csrf-cookie
```

### 2) Login
```bash
XSRF_TOKEN=$(grep XSRF-TOKEN cookies.txt | awk '{print $7}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

curl -i -b cookies.txt -c cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: ${XSRF_TOKEN}" \
  -X POST http://localhost/app/auth/login \
  -d '{"email":"test@example.com","password":"password"}'
```

### 3) Boot context (`/app/auth/me`)
```bash
curl -b cookies.txt http://localhost/app/auth/me
```

### 4) Switch active client
```bash
curl -b cookies.txt -c cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: ${XSRF_TOKEN}" \
  -X POST http://localhost/app/clients/YOUR_CLIENT_UUID/switch
```

### 5) Query dashboard home analytics
```bash
curl -b cookies.txt "http://localhost/app/analytics/summary?range=7d"
curl -b cookies.txt "http://localhost/app/analytics/timeseries?range=30d"
```

### 6) Read leads and patch status
```bash
curl -b cookies.txt "http://localhost/app/leads?page=1&per_page=20"

curl -b cookies.txt -c cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-XSRF-TOKEN: ${XSRF_TOKEN}" \
  -X PATCH http://localhost/app/leads/YOUR_LEAD_UUID \
  -d '{"status":"CONTACTED","notes":"Called and confirmed details."}'
```

### 7) Seed demo data quickly
```bash
php artisan demo:seed-dashboard --client="Acme Auctions" --reset
```
