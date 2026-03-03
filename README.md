# Chatbot API (Laravel)

Last updated: 2026-02-26

Backend for:

- Widget chatbot APIs (`/api/widget/*`)
- Dashboard APIs (`/app/*`) with session auth + CSRF + forced JSON responses

This document is the canonical implementation guide for current backend behavior.

---

## 1) What This System Does

This repo provides:

- A widget chatbot backend for conversation flow, event capture, and valuation orchestration.
- A dashboard backend for tenant users and platform admins to:
    - manage settings and domains
    - manage appraisal questions
    - review leads/conversations/valuations
    - import and manage product catalog data
    - view analytics

The dashboard is tenancy-aware. Every `/app/*` tenant endpoint runs in an **active client** context (`active_client_id` in session).

## 1.1 Business Logic (Plain-English)

This section explains each major backend area for non-technical stakeholders.

### Roles and access

- The platform serves multiple businesses (clients).
- A user can be:
    - a tenant user inside one client (`owner/admin/viewer`)
    - a platform operator across all clients (`support_admin/super_admin`)
- This prevents accidental cross-client data access while still enabling support and operations.

### Active client concept

- Every dashboard action happens in one selected client context.
- This acts like choosing which branch/account you are currently managing.
- It reduces mistakes and ensures reports/actions are scoped correctly.

### Settings and install

- Settings customize how the assistant looks and behaves.
- Domain allowlist controls where the widget can run.
- Embed endpoint gives a copy/paste script for website installation.

### Appraisal questions

- These questions shape data collection before valuation.
- Fixed question keys keep downstream reporting and valuation logic consistent.
- Clients can still choose how many questions to use and in what order.

### Leads workflow

- Leads represent real opportunities captured from chat interactions.
- Status and notes support sales operations and follow-up tracking.
- Export supports CRM/manual workflows outside the platform.

### Conversations and transcript

- Conversation history is the source of truth for what happened with each customer.
- Transcript + linked lead/valuation IDs make support and review much faster.

### Valuations

- Valuations transform captured data + catalog comparables into pricing outputs.
- Normalized outputs (confidence, currency, minor units) keep decisions consistent.

### Product catalog and imports

- Product catalog is the market-data foundation for valuation quality.
- Import flow validates files, maps fields, and processes rows safely in the background.
- Invalid rows are isolated with clear errors so teams can fix and retry quickly.

### Analytics

- Gives operational visibility on:
    - conversation activity
    - valuation throughput
    - lead generation
    - import volume/health
- Time-windowed metrics support daily and weekly management reporting.

### Logging and governance

- `app_logs` tracks business actions (who changed what and when).
- Application logs track technical behavior/failures.
- Together they support accountability, support audits, and incident debugging.

---

## 2) High-Level Architecture

### 2.1 API Surfaces

- `/api/widget/*`: end-customer/widget traffic.
- `/app/*`: authenticated dashboard traffic (session/cookie).

### 2.2 Dashboard request pipeline

1. `force.json.app` middleware sets `Accept: application/json`.
2. `app.auth` enforces authenticated web guard.
3. `set.current.client` resolves and validates active tenant context.
4. Route-level role middleware/policies enforce write/read permissions.

### 2.3 Core data domains

- Conversations/events/messages
- Valuations
- Leads
- Appraisal questions
- Product catalog
- Catalog import runs/errors
- Client settings
- App-level logs (`app_logs`)

---

## 3) Roles, Permissions, and Tenancy

## 3.1 Platform roles (`users.platform_role`)

- `none`
- `support_admin`
- `super_admin`

### Current behavior

- `super_admin`
    - full access across clients
    - appears as global admin
    - can switch into any client
    - bypasses policy checks via `Gate::before`
- `support_admin`
    - platform read-only allowlist (`viewAny`, `view`, `export`, `export_readonly`)
    - blocked from tenant write routes by `RequireTenantRole`

## 3.2 Tenant roles (`client_user.role`)

- `owner`
- `admin`
- `viewer`

### Current behavior

- `owner` / `admin`: tenant write + read operations
- `viewer`: read-only operations

## 3.3 Platform role vs tenant role

- Platform role is global capability.
- Tenant role is per-client capability.
- `super_admin` does not require pivot membership to operate in a client after switching.

## 3.4 Active client context

- Switch: `POST /app/clients/{client}/switch`
- Clear: `POST /app/clients/clear`
- Missing active client on tenant endpoint:
    - `409 { "error": "CONFLICT", "reason_code": "NO_ACTIVE_CLIENT" }`

---

## 4) Auth and Boot Contract

## 4.1 Endpoints

- `POST /app/auth/login`
- `POST /app/auth/logout`
- `GET /app/auth/me`

## 4.2 `/app/auth/me` is the boot payload

Response shape:

```json
{
  "user": {
    "id": "uuid-or-int",
    "name": "User",
    "email": "user@example.com",
    "platform_role": "none|support_admin|super_admin"
  },
  "active_client_id": "uuid|null",
  "active_client": { "id": "uuid", "name": "Client Name" } | null,
  "tenant_role": "owner|admin|viewer|null",
  "permissions": {
    "can_manage_settings": true,
    "can_manage_questions": true,
    "can_export_leads": true,
    "can_manage_imports": true
  }
}
```

Frontend should use this as source-of-truth for role-aware UI.

---

## 5) Data Model (Current Important Tables)

## 5.1 `client_user`

- Pivot for tenant membership.
- Role normalization migration maps legacy `member -> viewer`.
- Current code should treat tenant roles as `owner|admin|viewer`.

## 5.2 `client_settings`

Used by dashboard settings + embed flow. Relevant fields in active use:

- `client_id` (unique)
- `bot_name`
- `brand_color`
- `accent_color`
- `logo_url`
- `prompt_settings` (JSON)
- `allowed_origins` (JSON array)
- `widget_security_version` (int)

Also present in schema (currently not primary in endpoint contracts):

- `business_details`
- `widget_enabled`

## 5.3 `leads`

Relevant fields:

- identity fields (`name`, `email`, `phone_raw`, `phone_normalized`)
- lookup hashes (`email_hash`, `phone_hash`)
- workflow (`status`, `notes`, `updated_by`)

## 5.4 `product_catalog`

Relevant fields:

- `title`, `description`
- `source`, `currency`
- `price` (minor units)
- `low_estimate`, `high_estimate` (minor units)
- `sold_at`, `sold_at_key`
- `normalized_text`, `normalized_title_hash`

Deduping uniqueness uses:

- `client_id, source, normalized_title_hash, price, currency, sold_at_key`

## 5.5 `catalog_imports`

Relevant fields:

- `status`, `attempt`
- `file_path`, `file_hash`
- `validated_header`, `mapping`
- `totals`, `errors_count`, `errors_sample`
- `queued_at`, `started_at`, `finished_at`

## 5.6 `catalog_import_errors`

- DB-backed error storage (v1)
- fields: `import_id, row_number, column, message, raw, timestamps`

## 5.7 `app_logs`

Audit/event style table used via `AuditLog`/`AuditLogger`.
Important actions logged include:

- `client.switched`
- `client.settings.updated`
- `client.domains.updated`
- `lead.updated`
- `catalog.import.uploaded`
- `product.deleted`

---

## 6) Dashboard Endpoint Catalog

All endpoints below are under `/app` prefix.

## 6.1 Auth + client context

- `POST /auth/login`
- `POST /auth/logout`
- `GET /auth/me`
- `GET /clients`
- `POST /clients/{client}/switch`
- `POST /clients/clear`

`GET /clients` behavior:

- platform admins: all clients
- tenant users: only assigned clients

## 6.2 Settings + embed

- `GET /settings` (viewer+)
- `PUT /settings` (owner/admin, super_admin)
- `PUT /settings/domains` (owner/admin, super_admin)
- `GET /embed-code` (viewer+)

### 6.2.1 Settings update contract

- Accepts flattened keys:
    - `client_name`
    - `bot_name`
    - `brand_color`
    - `accent_color`
    - `logo_url`
    - `prompt_settings`
    - `intro_message` (stored into `prompt_settings.intro_message`)
- Unknown keys are ignored.
- `prompt_settings` is replace-all if provided.
- `PUT /settings` does **not** modify `allowed_origins` or `widget_security_version`.

### 6.2.2 Domains update contract

- Input: `allowed_origins: string[]` (max 50)
- Normalization/validation:
    - trims
    - no wildcard (`*` or host containing `*`)
    - no auth/query/fragment/path (except `/`)
    - only `https`, except local/testing localhost may use `http`
    - strips default ports (`:443` https, `:80` http)
    - preserves explicit non-default ports
    - de-duplicates normalized list
- Concurrency:
    - row lock + atomic increment of `widget_security_version`
- Response includes canonical saved origins and bumped version.

### 6.2.3 Embed contract

`GET /embed-code` returns:

```json
{
    "script_url": "https://...",
    "params": {
        "client_id": "uuid",
        "widget_security_version": 3
    },
    "widget_security_version": 3,
    "snippet": "<script src=\"...\" defer data-client-id=\"...\" data-widget-security-version=\"...\"></script>"
}
```

## 6.3 Appraisal questions

- `GET /appraisal-questions`
- `POST /appraisal-questions`
- `PUT /appraisal-questions/{id}`
- `DELETE /appraisal-questions/{id}` (hard delete)
- `PUT /appraisal-questions/reorder`

Alias routes exist under:

- `/settings/appraisal-questions...`

### 6.3.1 Locked question keys

Only these keys are accepted:

- `maker`
- `condition`
- `item_type`
- `age`
- `size`
- `material`

### 6.3.2 Other locks

- `order_index` is 1-based
- reorder compacts active questions to 1..N
- reorder requires exact active IDs
- `key` is immutable on update

## 6.4 Leads

- `GET /leads`
- `GET /leads/{id}`
- `PATCH /leads/{id}`
- `GET /leads/export`

### 6.4.1 List filters

- `page`, `per_page`
- `status`
- `range`
- `q`

`q` searches:

- `name`, `status`, `notes`

`q` does **not** search UUID columns.

### 6.4.2 PII display policy

- tenant users + `super_admin`: full PII
- `support_admin`: masked PII in list/detail/export

## 6.5 Conversations

- `GET /conversations`
- `GET /conversations/{id}/messages`
- `GET /conversations/{id}/events` (platform admin only)

### 6.5.1 List behavior

- supports `page`, `per_page`, optional `state`, optional `q`
- `q` searches:
    - `conversations.state`
    - `conversation_messages.content`
- `q` does **not** search UUID columns
- `range` currently ignored on this endpoint by product decision

### 6.5.2 Messages behavior

- ordered by `event_id ASC` (strict)
- response includes `lead_id` + `valuation_id` at top-level

## 6.6 Valuations

- `GET /valuations`
- `GET /valuations/{id}`

### 6.6.1 Contracts

- confidence normalized to `0..1`
- money fields in minor units
- explicit `currency` in response
- detail includes linked IDs:
    - `lead_id`
    - `conversation_id`

## 6.7 Products

- `GET /products`
- `GET /products/{id}`
- `DELETE /products/{id}` (owner/admin, super_admin)

### 6.7.1 List filters

- `page`, `per_page`, optional `q`, `source`, `currency`, `range`
- `q` searches normalized product text only (title/description index)
- no UUID `q` matching

### 6.7.2 Delete behavior

- hard delete
- audit log action: `product.deleted`

## 6.8 Analytics

- `GET /analytics/summary`
- `GET /analytics/timeseries`

### 6.8.1 Locked range semantics

- `today`: `00:00:00Z -> now`
- `7d`: `now-7d -> now`
- `30d`: `now-30d -> now`
- `all`: unbounded for summary; capped window for timeseries

### 6.8.2 Summary response includes

- `range`
- `client { id, name }`
- `from`, `to`
- `conversations`, `valuations`, `leads`, `catalog_imports`

Conversation analytics count basis:

- `last_activity_at` (not `created_at`)

### 6.8.3 Timeseries locks

- UTC day buckets
- ascending by date
- zero-filled missing days

## 6.9 Catalog imports

- `GET /catalog-imports`
- `POST /catalog-imports`
- `GET /catalog-imports/{id}`
- `POST /catalog-imports/{id}/upload`
- `POST /catalog-imports/{id}/validate`
- `POST /catalog-imports/{id}/start`
- `POST /catalog-imports/{id}/retry`
- `GET /catalog-imports/{id}/errors`

### 6.9.1 Lifecycle statuses

- `CREATED`
- `UPLOADED`
- `VALIDATED`
- `QUEUED`
- `RUNNING`
- `COMPLETED`
- `FAILED`

### 6.9.2 Start preconditions

- import must be `VALIDATED`
- file must exist
- mapping must validate
- otherwise:
    - `409 INVALID_IMPORT_STATE`
    - or `409 IMPORT_FILE_MISSING`
    - or `422` validation errors for mapping payload issues

### 6.9.3 Mapping rules (current)

Required:

- `title`

And one of:

- `price`
- `low_estimate` + `high_estimate`

Optional:

- `currency`, `source`, `description`, `sold_at`

### 6.9.4 Validate response contract

Always returns:

- `columns`
- `sample_rows`
- `suggested_mapping`
- `errors`
- `limits`

Sample size:

- from config (`catalog.sample_rows`, currently 25 by lock)

### 6.9.5 Errors endpoint contract

- `204` when no errors
- `200 text/csv` when errors exist
- ordered by `row_number ASC, id ASC`

### 6.9.6 Queue

- start dispatches `RunCatalogImportJob` to queue: `catalog-imports`

---

## 7) Logging and Observability

## 7.1 DB event logging (`app_logs`)

Written through `App\Services\AuditLogger`.

Common metadata convention:

- `actor_user_id` (column)
- `client_id` (column)
- `action`
- `meta` object (`before/after`, target IDs, request context like `ip`, `ua`)

## 7.2 Current audited actions

- `client.switched`
- `client.settings.updated`
- `client.domains.updated`
- `lead.updated`
- `catalog.import.uploaded`
- `product.deleted`

## 7.3 Application log stream

Laravel logger captures operational events such as:

- import transition/failure warnings/errors
- valuation job lifecycle
- widget origin allow/deny
- SSE connection cap denials

---

## 8) Error Contract

## 8.1 Standard deny/conflict shape

```json
{
    "error": "FORBIDDEN|CONFLICT|UNAUTHENTICATED",
    "reason_code": "..."
}
```

## 8.2 Common reason codes

- `UNAUTHENTICATED`
- `CSRF_INVALID`
- `NO_ACTIVE_CLIENT`
- `NOT_A_CLIENT_MEMBER`
- `INSUFFICIENT_ROLE`
- `PLATFORM_ROLE_REQUIRED`
- `INVALID_IMPORT_STATE`
- `IMPORT_FILE_MISSING`
- `IMPORT_RUNNING`
- `IMPORT_MAPPING_INVALID`

Validation errors remain standard Laravel `422` with `message` and `errors`.

---

## 9) List/Pagination Contract

All list endpoints use Laravel paginator payload (`data`, `links`, `meta`) and:

- accept `page`, `per_page`
- default `per_page = 20`
- enforce `max_per_page` from config

`meta.default_sort` is included in responses.

Default ordering:

- leads: `created_at desc`
- valuations: `created_at desc`
- conversations: `last_activity_at desc`
- catalog-imports: `created_at desc`
- products: `created_at desc`
- appraisal-questions: `order_index asc`

Unsupported sort params are ignored.

---

## 10) Frontend Integration Notes

1. Bootstrap app state with `GET /app/auth/me`.
2. Use `permissions` flags from `auth/me` for UI gating.
3. If `NO_ACTIVE_CLIENT`, route user to client picker flow.
4. For platform admins:
    - `GET /app/clients` shows all clients
    - switch context via `/app/clients/{id}/switch`
5. For catalog import UI:
    - create/reuse run (`POST /catalog-imports`)
    - upload file (`POST /catalog-imports/{id}/upload`) and use returned preview
    - start with mapping (`POST /catalog-imports/{id}/start`)
    - poll `GET /catalog-imports/{id}` for status + totals + next_action
    - download row errors from `/errors`

---

## 11) Environment and Runtime

## 11.1 Run locally (Sail)

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed
```

## 11.2 Test

```bash
./vendor/bin/sail artisan test
```

## 11.3 Queues

Catalog import processing uses queue:

- `catalog-imports`

Run worker accordingly, for example:

```bash
./vendor/bin/sail artisan queue:work --queue=catalog-imports,default
```

---

## 12) Seeded Users and Demo Data

## 12.1 Core seeded user

- `test@example.com`
- seeded as `platform_role=super_admin`

## 12.2 Dashboard demo users (per client slug)

- `owner+{client-slug}@example.com`
- `viewer+{client-slug}@example.com`
- `support+{client-slug}@example.com`

Seeder-set password where present:

- `password`

---

## 13) Recent Important Backend Changes

- `/app/settings` now supports flattened update payload.
- `/app/products` list/detail added; delete endpoint added.
- Product delete now writes `product.deleted` audit entry.
- Catalog upload now writes `catalog.import.uploaded` audit entry.
- Catalog detail payload expanded with lifecycle/preview/action flags.
- Import outcome tightened: no-imported-row scenarios can end as `FAILED`.
- Product catalog supports unsold estimate range (`low_estimate`, `high_estimate`).
- Valuation integration uses estimate midpoint when range data is provided.
- Conversations list:
    - ignores `range`
    - no UUID-based `q` search
- Leads/products search no longer query UUID columns.
- Appraisal question delete switched from deactivate to hard delete.
- Appraisal question key set locked to:
    - `maker`, `condition`, `item_type`, `age`, `size`, `material`
- Audit table usage renamed to `app_logs` via model + migration.

---

## 14) Known Constraints / TODOs

- `support_admin` is read-only by allowlist and tenant write middleware.
- Some legacy schema fields exist but are not central to current response contracts.
- Request-level global trace logging middleware (every request with latency/request-id in DB) is not yet implemented; current strategy is audited business events + application logs.

---

## 15) Business User Quick Start

This is an operational checklist for non-technical users.

## 15.1 Onboard a new client

1. Sign in to dashboard.
2. Select/switch to the client account.
3. Open settings and configure:
    - bot name
    - brand colors/logo
    - prompt behavior
4. Add allowed website origins.
5. Copy embed code and share with website owner for installation.

Success outcome:

- Widget can be installed on approved domains and uses the client’s branding.

## 15.2 Configure appraisal intake

1. Open appraisal questions.
2. Enable only the required questions from allowed keys:
    - maker, condition, item_type, age, size, material
3. Reorder questions to match business process.
4. Save and test a sample chat flow.

Success outcome:

- Intake flow captures consistent data needed by valuation.

## 15.3 Import product catalog data

1. Create/import run.
2. Upload CSV file.
3. Validate detected headers and sample rows.
4. Map fields and start import.
5. If errors exist, download error CSV, fix source file, and retry.

Success outcome:

- Valid rows are imported; invalid rows are clearly identified and recoverable.

## 15.4 Process leads

1. Open leads list (filter by status/range/search).
2. Review lead details.
3. Update status and notes after each customer interaction.
4. Export leads if needed for external sales workflow.

Success outcome:

- Lead pipeline reflects real follow-up progress and is report-ready.

## 15.5 Review conversations and valuations

1. Open conversation list and inspect transcript for context.
2. Open linked valuation details for pricing output and confidence.
3. Use linked IDs (lead/conversation) to follow an item end-to-end.

Success outcome:

- Teams can explain valuation outcomes and support decisions with traceable context.

## 15.6 Monitor operations

1. Check analytics summary for current period.
2. Review trends in timeseries.
3. Track import outcomes and error rates.
4. Use app logs + audit actions when investigating issues.

Success outcome:

- Managers get a reliable operational view of activity and quality.

## Things for dev

sail artisan queue:work --queue=catalog-imports,mail,ai,default
Local site for emails http://localhost:8026/
alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'
sail artisan db:seed --class=AppraisalQuestionSeeder
