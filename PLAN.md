# Catalog Import Final Locks

## Summary
These locks keep catalog-import behavior deterministic and frontend handling simple.

## Lock 1: Single state-transition error code
For any invalid import state transition (including start-before-validate), return:

```json
{
  "error": "CONFLICT",
  "reason_code": "INVALID_IMPORT_STATE"
}
```

### Logging requirement
Whenever an invalid transition occurs, logs must include:
- `current_status`
- `expected_statuses`
- `import_id`
- `client_id`

No alternate reason code for "not validated".

## Lock 2: PriceParser precision rule (strict v1)
### Accepted
- `95`
- `95.0`
- `95.00`

### Rejected
- `95.555` (more than 2dp)
- `95,00` (comma decimal unsupported in v1)
- negatives
- blanks

If currency symbols are supported, they must be explicitly stripped before validation.

### Error handling split
- Request-level mapping/state errors: `409` + `reason_code`.
- Row-level data errors (including invalid price): recorded as import row errors by job; do not fail request.

## Lock 3: Explicit upsert conflict updates
On product-catalog dedupe conflict, update only:
- `title`
- `description`
- `sold_at`
- `sold_at_key`
- `updated_at`

Never overwrite:
- `client_id`
- `source`
- `price`
- `currency`
- `normalized_title_hash`

This preserves dedupe identity stability.

## Lock 4: Errors storage (v1 locked choice)
v1 stores row-level import errors in Postgres in `catalog_import_errors`.

`GET /app/catalog-imports/{id}/errors` streams a CSV generated from DB rows.

No `errors.csv` file is written in v1.

The import row stores:
- `errors_count`
- `errors_sample` (first N)

Error row schema (locked):
- `import_id`
- `row_number`
- `column` (nullable)
- `message`
- `raw` (json/jsonb)
- `created_at`
- `updated_at`

### Why this is better for your dashboard
- enables pagination/search later (show row numbers + messages)
- avoids file lifecycle complexity (cleanup, path security, retention)

### Acceptance
- A run with invalid rows produces DB error records.
- Errors endpoint returns:
  - `204` if `errors_count == 0`
  - `200` `text/csv` streaming rows if `errors_count > 0`
- Error rows are retained indefinitely for MVP (cleanup policy deferred).

## Lock 5: Mapping fields (v1 locked)
Allowed mapping keys are exactly:
- `title`
- `price`
- `currency`
- `source`
- `description`
- `sold_at`

Implement as one canonical source:
- `App\Enums\CatalogMappingField` (or `config/catalog.php` `mapping_fields`)

Used by:
- `StartCatalogImportRequest`
- controller header-membership validation
- `RunCatalogImportJob`

### Acceptance
- Unknown mapping keys return `422` with field errors.
- All places reference the same list (no duplicated arrays).

## Assumptions
1. Frontend keys transition handling on `reason_code=INVALID_IMPORT_STATE`.
2. Strict precision is preferred over implicit rounding for >2dp values.
3. Dedupe identity columns are immutable after first insert.
