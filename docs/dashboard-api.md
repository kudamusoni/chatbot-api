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
Flat payload contract (others ignored for forward compatibility):
- `client_name`
- `bot_name`
- `brand_color`
- `accent_color`
- `logo_url`
- `prompt_settings` (replace entire object when provided)
- `fallback_message` (stored at `prompt_settings.fallback_message`)

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

## Onboarding + Email Verification + Team Invites

### Auth Boot Behavior for Unverified Users
`GET /app/auth/me` returns `200` for authenticated but unverified users with:
- `user.verified = false`
- `requires_email_verification = true`
- minimal context (`active_client_id`, `active_client`, `tenant_role`)
- `permissions = []`

Protected `/app/*` endpoints require verified email and return:
- `403 { "error":"FORBIDDEN", "reason_code":"EMAIL_NOT_VERIFIED" }`

### Public Onboarding Endpoints

#### `POST /app/onboarding/register`
Creates owner account + client + owner membership atomically and logs user in.

Request:
```json
{
  "name": "Kuda",
  "email": "kuda@email.com",
  "password": "********",
  "company_name": "Acme Auctions"
}
```

Responses:
- `201` boot payload (`requires_email_verification=true`)
- `409` `EMAIL_TAKEN`
- `422` validation errors

#### `GET /app/onboarding/verify-email`
Verifies signed verification link query params (`id`, `hash`, `expires`, `signature`).

Responses:
- `200 { "ok": true }` (idempotent)
- `409 VERIFY_LINK_INVALID`
- `409 VERIFY_LINK_EXPIRED`

#### `POST /app/onboarding/resend-verification`
Auth required. Sends queued verification email for unverified users.

Responses:
- `200 { "ok": true }`
- `429 RATE_LIMITED`

#### `GET /app/onboarding/invitations/preview?token=...`
Returns invitation preview.

Response:
```json
{
  "client_name": "Acme Auctions",
  "role": "admin",
  "expires_at": "2026-02-26T12:00:00Z"
}
```

Errors:
- `404 INVITE_INVALID`
- `409 INVITE_ACCEPTED`
- `410 INVITE_EXPIRED`
- `410 INVITE_REVOKED`

#### `POST /app/onboarding/invitations/accept`
Accepts invite token, creates/attaches user membership, starts session.

Request:
```json
{
  "token": "raw-token",
  "name": "Invitee Name",
  "password": "********"
}
```

Responses:
- `200` boot payload
- `404 INVITE_INVALID`
- `409 INVITE_ACCEPTED`
- `410 INVITE_EXPIRED`
- `410 INVITE_REVOKED`
- `422` when new user details are missing

### Team Management Endpoints (Auth + Verified + Active Client)

#### `POST /app/team/invitations` (owner/admin)
Creates or rotates a pending invite.

Request:
```json
{ "email": "staff@example.com", "role": "admin" }
```

Response:
```json
{ "ok": true, "invitation_id": "uuid", "expires_at": "2026-02-26T12:00:00Z" }
```

Errors:
- `409 ALREADY_MEMBER`
- `422` validation errors

#### `GET /app/team/invitations` (owner/admin)
Lists invites with derived `status` (`pending|accepted|revoked|expired`).

#### `POST /app/team/invitations/{id}/revoke` (owner/admin)
Revokes invite (idempotent).

#### `GET /app/team/members` (owner/admin)
Lists team members:
```json
{
  "data": [
    { "id": 1, "name": "Owner", "email": "owner@x.com", "role": "owner", "joined_at": "2026-02-26T12:00:00Z" }
  ]
}
```

#### `PATCH /app/team/members/{userId}` (owner only)
Request:
```json
{ "role": "admin" }
```

#### `POST /app/team/members/{userId}/remove` (owner only)
Removes membership (self-removal blocked for MVP).

### Audit Actions Added
- `user.registered`
- `user.email.verified`
- `user.email.verification_resent`
- `client.created`
- `invite.created`
- `invite.resent`
- `invite.accepted`
- `invite.revoked`
- `client.member.added`
- `client.member.role_updated`
- `client.member.removed`

### New Reason Codes
- `EMAIL_NOT_VERIFIED`
- `EMAIL_TAKEN`
- `VERIFY_LINK_INVALID`
- `VERIFY_LINK_EXPIRED`
- `RATE_LIMITED`
- `INVITE_INVALID`
- `INVITE_ACCEPTED`
- `INVITE_EXPIRED`
- `INVITE_REVOKED`
- `ALREADY_MEMBER`

## Demo Data (Dev)

### Command
`php artisan demo:seed-dashboard --client=\"Acme Auctions\" [--reset]`

Behavior:
- `--reset`: clears demo data for the target client only (dev-only operation).
- without `--reset`: idempotent (no duplicate demo records).
