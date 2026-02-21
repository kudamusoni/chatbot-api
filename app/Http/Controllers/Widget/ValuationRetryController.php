<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Enums\ValuationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\ValuationRetryRequest;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Valuation;
use App\Services\ConversationEventRecorder;
use App\Services\TurnLifecycleRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ValuationRetryController extends Controller
{
    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder,
        private readonly TurnLifecycleRecorder $turnLifecycle
    ) {}

    /**
     * Retry a failed valuation.
     *
     * POST /api/widget/valuation/retry
     */
    public function store(ValuationRetryRequest $request): JsonResponse
    {
        $clientId = $request->validated('client_id');
        $sessionToken = $request->validated('session_token');
        $actionId = $request->validated('action_id');

        $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);

        if (!$conversation) {
            return response()->json([
                'error' => 'Invalid session token',
            ], 401);
        }

        // Check for idempotent retry - if action was already processed, return success
        $existingEvent = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('idempotency_key', $actionId)
            ->first();

        if ($existingEvent) {
            return response()->json([
                'ok' => true,
                'last_event_id' => $conversation->last_event_id,
            ]);
        }

        // Only allow retry from VALUATION_FAILED state
        if (!$conversation->state->canRetryValuation()) {
            return response()->json([
                'error' => 'Cannot retry valuation in current state',
                'state' => $conversation->state->value,
            ], 409);
        }

        // Find the latest failed valuation for this conversation
        $failedValuation = Valuation::where('conversation_id', $conversation->id)
            ->where('status', ValuationStatus::FAILED)
            ->latest()
            ->first();

        if (!$failedValuation) {
            return response()->json([
                'error' => 'No failed valuation found to retry',
            ], 409);
        }

        $startedAt = microtime(true);
        $this->turnLifecycle->recordStarted($conversation, $actionId, 'valuation.retry');

        try {
            DB::transaction(function () use ($conversation, $failedValuation, $actionId) {
                // Use same snapshot for retry (deterministic)
                $inputSnapshot = $failedValuation->input_snapshot;
                $snapshotHash = $failedValuation->snapshot_hash;

                // Reset the failed valuation to PENDING so job can re-run
                $failedValuation->update([
                    'status' => ValuationStatus::PENDING,
                    'result' => null,
                ]);

                // Emit valuation.requested with same snapshot_hash (idempotent)
                $this->eventRecorder->record(
                    $conversation,
                    ConversationEventType::VALUATION_REQUESTED,
                    [
                        'snapshot_hash' => $snapshotHash,
                        'input_snapshot' => $inputSnapshot,
                        'conversation_id' => $conversation->id,
                        'retry' => true,
                    ],
                    idempotencyKey: $actionId, // Use action_id for this specific retry
                    correlationId: $actionId
                );
            });

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->turnLifecycle->recordCompleted($conversation, $actionId, 'valuation.retry', $latencyMs);
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->turnLifecycle->recordFailed($conversation, $actionId, 'valuation.retry', $latencyMs, $e);
            throw $e;
        }

        $conversation->refresh();

        return response()->json([
            'ok' => true,
            'last_event_id' => $conversation->last_event_id,
        ]);
    }
}
