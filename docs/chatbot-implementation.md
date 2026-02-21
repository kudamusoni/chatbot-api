# Chatbot Implementation and Data Flow

## Purpose
This document explains how the widget chatbot works in this Laravel repo: request flow, state transitions, event sourcing, projections, and how data moves from user message to persisted outcomes.

## High-level Architecture
The chatbot backend is built around an event-sourced conversation model:

1. Widget calls public `/api/widget/*` endpoints.
2. Controllers validate input and write immutable `conversation_events`.
3. `ConversationProjector` listens to event writes and updates read models:
- `conversations` (state, last activity)
- `conversation_messages` (chat transcript)
- `valuations` (valuation projection)
- `leads` (lead projection)
4. Widget uses `history` + `sse` to render and stay in sync.

Core idea: business logic emits domain events first; read models are derived from events.

## Public Widget Endpoints
Routes are defined in `routes/api.php` under `Route::prefix('widget')->middleware('widget.origin')`.

- `POST /api/widget/bootstrap`
- `POST /api/widget/chat`
- `POST /api/widget/appraisal/confirm`
- `POST /api/widget/lead/confirm-identity`
- `POST /api/widget/back-to-chat`
- `POST /api/widget/valuation/retry`
- `POST /api/widget/reset`
- `GET /api/widget/history`
- `GET /api/widget/sse`

All widget endpoints are protected by origin/session controls via middleware and token/client scoping.

## Core Entities

### 1) `conversations`
Projection row per widget session.

Important fields:
- `id`, `client_id`, `session_token_hash`
- `state` (`CHAT`, `APPRAISAL_INTAKE`, `APPRAISAL_CONFIRM`, `LEAD_INTAKE`, `LEAD_IDENTITY_CONFIRM`, `VALUATION_RUNNING`, `VALUATION_READY`, `VALUATION_FAILED`)
- appraisal fields: `appraisal_answers`, `appraisal_current_key`, `appraisal_snapshot`
- lead fields: `lead_answers`, `lead_current_key`, `lead_identity_candidate`
- streaming cursors: `last_event_id`, `last_activity_at`

### 2) `conversation_events`
Immutable event log (source of truth).

Important fields:
- `id` (BIGINT sequence), `conversation_id`, `client_id`
- `type` (`ConversationEventType`)
- `payload` (JSON)
- `correlation_id` (trace across a single turn)
- `idempotency_key` (dedupe/retry safety)
- `created_at`

### 3) `conversation_messages`
Read model for transcript.

Important fields:
- `conversation_id`, `event_id`, `role`, `content`, `created_at`
- unique `(conversation_id, event_id)` for idempotent projection

### 4) `valuations`
Read model for valuation lifecycle/results.

Important fields:
- `status` (`PENDING|RUNNING|COMPLETED|FAILED`)
- `snapshot_hash`, `input_snapshot`, `result`

### 5) `leads`
Read model for captured lead details.

Important fields:
- `name`, encrypted `email`, encrypted `phone_*`
- lookup hashes (`email_hash`, `phone_hash`)
- `status`, `notes`, `updated_by`

## Request-to-Response Flow

### A) Bootstrap (`POST /api/widget/bootstrap`)
1. Validate `client_id`, optional `session_token`.
2. Try resume by `Conversation::findByTokenForClient(token, client_id)`.
3. If not found, create conversation + new session token.
4. Return:
- `session_token`
- `conversation_id`
- `last_event_id`
- `last_activity_at`
- `widget_security_version`

### B) Chat (`POST /api/widget/chat`)
1. Resolve conversation by token + client.
2. Generate `correlation_id`.
3. Inside DB transaction:
- record `user.message.created` (idempotent by `message_id`)
- if newly created, record turn telemetry (`turn.started`), call orchestrator, then `turn.completed` or `turn.failed`
4. Return `ok`, `conversation_id`, `correlation_id`, `last_event_id`.

### C) Orchestrator (`ConversationOrchestrator`)
Orchestrator reads current conversation state + message intent and emits follow-up events:

- starts appraisal flow when valuation intent detected
- asks/records appraisal question answers
- requests confirmation snapshot
- on confirmation path, emits valuation request trigger event
- lead flow with optional identity confirmation reuse path
- fallback assistant responses for unsupported contexts

Everything is event-driven, and the projector updates read models from these events.

## Projection Flow (`ConversationProjector`)
For each `ConversationEventRecorded`:
1. Always update conversation cursor fields:
- `last_event_id = event.id`
- `last_activity_at = event.created_at`
2. For message events, project into `conversation_messages`.
3. For appraisal/lead/valuation events, mutate projection state rows.
4. `turn.*` events are telemetry only (no orchestration recursion).

This keeps write-side immutable and read-side query-friendly.

## History + SSE Model

### History (`GET /api/widget/history`)
Used for initial render:
- returns ordered messages
- returns current conversation state panel data
- returns latest valuation summary

### SSE (`GET /api/widget/sse`)
Used for live updates:
- validates token/client
- accepts cursor via `Last-Event-ID` or `after_id`
- replays missed events in ascending ID order
- streams keepalive pings
- enforces replay windows / cursor validity / per-session limits

This two-step pattern gives fast startup + resilient live sync.

## Idempotency and Consistency
- User turns are idempotent via client `message_id` as event key.
- Projection writes are idempotent by unique constraints and first-or-create patterns.
- Turn lifecycle markers wrap orchestration to give deterministic telemetry.
- Cross-tenant access is blocked by client-scoped token lookup everywhere.

## Valuation Processing
- `valuation.requested` event is produced from confirmed appraisal.
- `RunValuationJob` loads valuation with lock, marks running, computes deterministic result via `ValuationEngine`, and emits `valuation.completed` or `valuation.failed`.
- Results are persisted in projection row and available to history/dashboard APIs.

## Lead PII Handling
- Lead emails/phones are encrypted at rest.
- hash columns support lookup/filter use-cases without decrypting in SQL.
- normalization helpers ensure deterministic hashing and phone format behavior.

## Operational Notes
- SSE has connection cap + replay limits + age-window guards.
- Origin allowlist controls widget access.
- `widget_security_version` supports config drift debugging across deployments.

## File Map (Main Touchpoints)
- Routes: `routes/api.php`
- Widget controllers: `app/Http/Controllers/Widget/*`
- Orchestrator: `app/Services/ConversationOrchestrator.php`
- Event recording: `app/Services/ConversationEventRecorder.php`
- Turn telemetry: `app/Services/TurnLifecycleRecorder.php`
- Projector: `app/Projectors/ConversationProjector.php`
- Models: `app/Models/Conversation.php`, `app/Models/ConversationEvent.php`, `app/Models/ConversationMessage.php`, `app/Models/Valuation.php`, `app/Models/Lead.php`

## Getting Started (curl Flows)

### 1) Bootstrap a widget session
```bash
curl -X POST http://localhost/api/widget/bootstrap \
  -H "Content-Type: application/json" \
  -H "Origin: https://demo.example.com" \
  -d '{
    "client_id": "YOUR_CLIENT_UUID"
  }'
```

Save:
- `session_token`
- `conversation_id`
- `last_event_id`

### 2) Send a user message
```bash
curl -X POST http://localhost/api/widget/chat \
  -H "Content-Type: application/json" \
  -H "Origin: https://demo.example.com" \
  -d '{
    "client_id": "YOUR_CLIENT_UUID",
    "session_token": "SESSION_TOKEN_FROM_BOOTSTRAP",
    "message_id": "msg-001",
    "text": "Can you value this item?"
  }'
```

### 3) Load transcript snapshot
```bash
curl "http://localhost/api/widget/history?client_id=YOUR_CLIENT_UUID&session_token=SESSION_TOKEN_FROM_BOOTSTRAP" \
  -H "Origin: https://demo.example.com"
```

### 4) Open SSE stream for live updates
```bash
curl -N "http://localhost/api/widget/sse?client_id=YOUR_CLIENT_UUID&session_token=SESSION_TOKEN_FROM_BOOTSTRAP&after_id=0" \
  -H "Origin: https://demo.example.com"
```

### 5) Confirm appraisal snapshot (example)
```bash
curl -X POST http://localhost/api/widget/appraisal/confirm \
  -H "Content-Type: application/json" \
  -H "Origin: https://demo.example.com" \
  -d '{
    "client_id": "YOUR_CLIENT_UUID",
    "session_token": "SESSION_TOKEN_FROM_BOOTSTRAP",
    "message_id": "confirm-001",
    "confirm": true
  }'
```
