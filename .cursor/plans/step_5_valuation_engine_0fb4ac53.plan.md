---
name: Step 5 Valuation Engine
overview: Implement a deterministic valuation engine that computes price estimates from client-scoped product catalog data. When `valuation.requested` is emitted, a job runs the engine and emits `valuation.completed` with median, range, and confidence metrics.
todos:
  - id: 5.1a
    content: Create product_catalog migration with columns, indexes, and pg_trgm trigram index
    status: completed
  - id: 5.1a2
    content: Create ProductSource enum (sold, asking, estimate)
    status: completed
  - id: 5.1b
    content: Create ProductCatalog model with scopes, normalized_text auto-compute, and enum cast
    status: completed
    dependencies:
      - 5.1a
      - 5.1a2
  - id: 5.1c
    content: Create ProductCatalogSeeder with ~20 sample comps
    status: completed
    dependencies:
      - 5.1b
  - id: 5.1d
    content: Create ValuationEngine service with compute() algorithm
    status: completed
    dependencies:
      - 5.1b
  - id: 5.1e
    content: Create ValuationEngineTest with zero-data, median/range, confidence tests
    status: completed
    dependencies:
      - 5.1d
  - id: 5.2a
    content: Create RunValuationJob with idempotency and event emission
    status: completed
    dependencies:
      - 5.1d
  - id: 5.2b
    content: Update projector valuation.completed to find by snapshot_hash
    status: completed
    dependencies:
      - 5.2a
  - id: 5.2c
    content: Create RunValuationJobTest
    status: completed
    dependencies:
      - 5.2a
  - id: 5.3a
    content: Create DispatchValuationJob listener and register in EventServiceProvider
    status: completed
    dependencies:
      - 5.2a
  - id: 5.3b
    content: Update AppraisalConfirmController valuation.requested payload
    status: completed
    dependencies:
      - 5.3a
  - id: 5.3c
    content: Run full test suite to verify Step 5 complete
    status: completed
    dependencies:
      - 5.3b
---

# Step 5: Deterministic Valuation Engine

## Current State

**Already implemented:**

- `Valuation` model with `snapshot_hash`, `input_snapshot`, `result`, status transitions
- Projector handles `valuation.requested` (creates row) and `valuation.completed` (stores result)
- Confirm controller emits `valuation.requested` with snapshot payload
- SSE streaming delivers events to widget

**Needs to be built:**

- `product_catalog` table + model (client-scoped comps)
- `ValuationEngine` service (deterministic algorithm)
- `RunValuationJob` job (dispatched on valuation.requested)
- Job dispatch wiring (listener on event)
- Tests

---

## Implementation

### Step 5.1: Product Catalog + Engine Skeleton

**A) Migration: `product_catalog` table**

```php
// Columns
id (uuid pk)
client_id (uuid, indexed)
title (string)
description (text nullable)
source (string: 'sold'|'asking'|'estimate')
price (integer, in cents/pence for precision)
currency (string, default 'GBP')
sold_at (timestamp nullable)
normalized_text (text) // lowercase title+description for search
timestamps

// Indexes
(client_id, source)
(client_id, sold_at)

// Trigram index for fast ILIKE search (requires pg_trgm extension)
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX product_catalog_normalized_text_trgm
ON product_catalog USING gin (normalized_text gin_trgm_ops);
```

**Currency Handling Rule:**

- `price` is stored as integer (cents/pence) - no floating point
- Only compute comps in the **same currency** as the client's default
- No currency conversion in Step 5 (would require exchange rates)
- Filter: `WHERE currency = $clientCurrency`

**B) Model: `ProductCatalog`**

- Fillable attributes, casts
- `scopeForClient()` for tenant isolation
- Auto-compute `normalized_text` on save (boot method)
- `source` as PHP enum: `ProductSource::Sold|Asking|Estimate`

**C) Seeder: Sample comps for Heritage Auctions**

Seed ~20 items with varied prices for "Royal Doulton", "porcelain", etc.

**D) Service: `ValuationEngine::compute()`**

Located at [`app/Services/ValuationEngine.php`](app/Services/ValuationEngine.php)

Algorithm (v1):

1. Tokenize snapshot values (maker, material, etc.)
2. Query `product_catalog` where `normalized_text ILIKE %term%`
3. Cap at 200 rows, bucket by source (sold/asking/estimate)
4. Compute median, percentile range (p25-p75 if count >= 5, else min-max)
5. Calculate confidence using explicit rubric (see below)
6. Return result object

**Confidence Scoring Rubric (0-3):**

```
base = 0
+1 if count >= 5
+1 if sold_count >= 1
+1 if sold_count >= 3 OR count >= 15
cap at 3
```

**Zero-Match Result Contract:**

When no comps are found, return exactly:

```json
{
  "count": 0,
  "range": null,
  "median": null,
  "confidence": 0,
  "data_quality": "internal",
  "signals_used": { "sold": 0, "asking": 0, "estimates": 0 }
}
```

**E) Tests: `ValuationEngineTest`**

- Zero comps returns exact zero-match contract
- Correct median/range for known inputs
- Confidence scoring matches rubric exactly

---

### Step 5.2: Job + Event Emission

**A) Job: `RunValuationJob`**

Located at [`app/Jobs/RunValuationJob.php`](app/Jobs/RunValuationJob.php)

**Input Source of Truth:**

- The `valuations` row is the source of truth for `input_snapshot`
- Event payload includes `input_snapshot` as convenience (for debugging/replay)
- Job reads from `valuation->input_snapshot`, not event payload

Flow:

```php
DB::transaction(function () use ($valuationId) {
    // 1. Load with lock to prevent concurrent execution
    $valuation = Valuation::lockForUpdate()->find($valuationId);
    
    // 2. Idempotency check (inside lock)
    if (!$valuation || $valuation->status->isTerminal()) {
        return; // Already completed/failed
    }
    
    // 3. Mark RUNNING
    $valuation->markRunning();
    
    // 4. Read input_snapshot from valuation row (source of truth)
    $result = $this->engine->compute(
        $valuation->client_id,
        $valuation->input_snapshot
    );
    
    // 5. Record valuation.completed event
    $this->eventRecorder->record(
        $valuation->conversation,
        ConversationEventType::VALUATION_COMPLETED,
        [
            'snapshot_hash' => $valuation->snapshot_hash,
            'status' => 'COMPLETED',
            'result' => $result,
        ],
        idempotencyKey: "val:{$valuation->snapshot_hash}:completed"
    );
    
    // 6. Mark COMPLETED (projector also does this, but belt + suspenders)
    $valuation->markCompleted($result);
});
```

**Why `lockForUpdate()`?**

- Prevents double-compute under queue retries
- Two jobs dispatched close together will serialize on the row lock
- Second job sees RUNNING/COMPLETED status and exits

**B) Update projector for `valuation.completed`**

Current projector finds valuation by "latest pending/running" which is fragile. Update to find by `snapshot_hash` from event payload.

**C) Tests: `RunValuationJobTest`**

- Creates `valuation.completed` event
- Updates valuation to COMPLETED
- Updates conversation state to VALUATION_READY
- Running twice doesn't duplicate

---

### Step 5.3: Wire Job Dispatch

**A) Event Listener: `DispatchValuationJob`**

Listen to `ConversationEventRecorded` and dispatch `RunValuationJob` when event type is `VALUATION_REQUESTED`.

**Dispatch Guard (idempotent on replays):**

```php
// Only dispatch if valuation exists and is PENDING
$valuation = Valuation::where('conversation_id', $event->conversation_id)
    ->where('snapshot_hash', $event->payload['snapshot_hash'])
    ->first();

if (!$valuation || $valuation->status !== ValuationStatus::PENDING) {
    return; // Already running/completed, skip dispatch
}

RunValuationJob::dispatch($valuation->id);
```

This prevents duplicate job dispatches during event replays or debug scenarios.

**B) Update `valuation.requested` payload**

In [`app/Http/Controllers/Widget/AppraisalConfirmController.php`](app/Http/Controllers/Widget/AppraisalConfirmController.php):

```php
// Current: just $snapshot
// Updated payload:
[
    'snapshot_hash' => $snapshotHash,
    'input_snapshot' => $snapshot,
    'conversation_id' => $conversation->id,
]
```

**C) End-to-end test**

Confirm flow: confirm → valuation.requested → job runs → valuation.completed → SSE delivers result

---

## File Summary

| File | Action |

|------|--------|

| `database/migrations/..._create_product_catalog_table.php` | Create |

| `app/Models/ProductCatalog.php` | Create |

| `database/seeders/ProductCatalogSeeder.php` | Create |

| `app/Services/ValuationEngine.php` | Create |

| `app/Jobs/RunValuationJob.php` | Create |

| `app/Listeners/DispatchValuationJob.php` | Create |

| `app/Providers/EventServiceProvider.php` | Update (register listener) |

| `app/Http/Controllers/Widget/AppraisalConfirmController.php` | Update payload |

| `app/Projectors/ConversationProjector.php` | Update valuation.completed lookup |

| `tests/Feature/Domain/ValuationEngineTest.php` | Create |

| `tests/Feature/Jobs/RunValuationJobTest.php` | Create |
