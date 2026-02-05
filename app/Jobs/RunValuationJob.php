<?php

namespace App\Jobs;

use App\Enums\ConversationEventType;
use App\Models\Valuation;
use App\Services\ConversationEventRecorder;
use App\Services\ValuationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to run valuation computation.
 *
 * Uses lockForUpdate() to prevent concurrent execution and
 * idempotency checks to handle replays safely.
 */
class RunValuationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $valuationId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ValuationEngine $engine, ConversationEventRecorder $eventRecorder): void
    {
        try {
            DB::transaction(function () use ($engine, $eventRecorder) {
                // 1. Load with lock to prevent concurrent execution
                $valuation = Valuation::lockForUpdate()->find($this->valuationId);

                if (!$valuation) {
                    Log::warning('RunValuationJob: Valuation not found', [
                        'valuation_id' => $this->valuationId,
                    ]);

                    return;
                }

                // 2. Idempotency check (inside lock)
                if ($valuation->status->isTerminal()) {
                    Log::info('RunValuationJob: Valuation already in terminal state', [
                        'valuation_id' => $this->valuationId,
                        'status' => $valuation->status->value,
                    ]);

                    return;
                }

                // 3. Mark RUNNING
                $valuation->markRunning();

                // 4. Read input_snapshot from valuation row (source of truth)
                $result = $engine->compute(
                    $valuation->client_id,
                    $valuation->input_snapshot
                );

                // 5. Record valuation.completed event
                $conversation = $valuation->conversation;

                if ($conversation) {
                    $eventRecorder->record(
                        $conversation,
                        ConversationEventType::VALUATION_COMPLETED,
                        [
                            'snapshot_hash' => $valuation->snapshot_hash,
                            'status' => 'COMPLETED',
                            'result' => $result,
                        ],
                        idempotencyKey: "val:{$valuation->snapshot_hash}:completed"
                    );
                }

                // 6. Mark COMPLETED (projector also does this, but belt + suspenders)
                $valuation->markCompleted($result);

                Log::info('RunValuationJob: Valuation completed', [
                    'valuation_id' => $this->valuationId,
                    'count' => $result['count'],
                    'confidence' => $result['confidence'],
                ]);
            });
        } catch (\Throwable $e) {
            $this->handleFailure($e, app(ConversationEventRecorder::class));
            throw $e; // Re-throw to trigger retry/failed handling
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('RunValuationJob: Job failed', [
            'valuation_id' => $this->valuationId,
            'exception' => $exception?->getMessage(),
        ]);

        $this->handleFailure($exception, app(ConversationEventRecorder::class));
    }

    /**
     * Common failure handling: mark valuation failed and emit event.
     */
    private function handleFailure(?\Throwable $exception, ConversationEventRecorder $eventRecorder): void
    {
        $valuation = Valuation::find($this->valuationId);

        if (!$valuation || $valuation->status->isTerminal()) {
            return;
        }

        $errorMessage = $exception?->getMessage() ?? 'Unknown error';

        // Emit valuation.failed event for UI/debugging
        $conversation = $valuation->conversation;

        if ($conversation) {
            try {
                $eventRecorder->record(
                    $conversation,
                    ConversationEventType::VALUATION_FAILED,
                    [
                        'snapshot_hash' => $valuation->snapshot_hash,
                        'error' => $errorMessage,
                        'error_code' => 'COMPUTATION_ERROR',
                    ],
                    idempotencyKey: "val:{$valuation->snapshot_hash}:failed"
                );
            } catch (\Throwable $e) {
                Log::error('RunValuationJob: Failed to emit valuation.failed event', [
                    'valuation_id' => $this->valuationId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // Mark valuation as failed
        $valuation->markFailed([
            'error' => $errorMessage,
        ]);
    }
}
