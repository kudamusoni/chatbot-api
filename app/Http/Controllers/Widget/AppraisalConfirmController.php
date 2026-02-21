<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Enums\ValuationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\AppraisalConfirmRequest;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Valuation;
use App\Services\ConversationEventRecorder;
use App\Services\TurnLifecycleRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AppraisalConfirmController extends Controller
{
    private const CANCELLATION_FOLLOW_UP_MESSAGE = 'Is there anything else that you needed info about?';

    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder,
        private readonly TurnLifecycleRecorder $turnLifecycle
    ) {}

    /**
     * Confirm or cancel appraisal.
     *
     * POST /api/widget/appraisal/confirm
     */
    public function store(AppraisalConfirmRequest $request): JsonResponse
    {
        $clientId = $request->validated('client_id');
        $sessionToken = $request->validated('session_token');
        $actionId = $request->validated('action_id');
        $confirm = $request->validated('confirm');

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

        // Only allow confirm/cancel from APPRAISAL_CONFIRM state
        if ($conversation->state !== ConversationState::APPRAISAL_CONFIRM) {
            return response()->json([
                'error' => 'Conversation is not awaiting confirmation',
            ], 409);
        }

        $startedAt = microtime(true);
        $this->turnLifecycle->recordStarted($conversation, $actionId, 'appraisal.confirm');

        try {
            DB::transaction(function () use ($conversation, $confirm, $actionId) {
                if ($confirm) {
                    $this->eventRecorder->record(
                        $conversation,
                        ConversationEventType::APPRAISAL_CONFIRMED,
                        [],
                        idempotencyKey: $actionId,
                        correlationId: $actionId
                    );

                    $inputSnapshot = $conversation->appraisal_snapshot
                        ?? $conversation->appraisal_answers
                        ?? [];

                    $snapshotHash = Valuation::generateSnapshotHash($inputSnapshot);

                    // Emit valuation.requested with structured payload
                    $requested = $this->eventRecorder->record(
                        $conversation,
                        ConversationEventType::VALUATION_REQUESTED,
                        [
                            'snapshot_hash' => $snapshotHash,
                            'input_snapshot' => $inputSnapshot,
                            'conversation_id' => $conversation->id,
                        ],
                        idempotencyKey: $snapshotHash,
                        correlationId: $actionId
                    );

                    if (!$requested['created']) {
                        $valuation = Valuation::where('conversation_id', $conversation->id)
                            ->where('snapshot_hash', $snapshotHash)
                            ->first();

                        if ($valuation?->status === ValuationStatus::COMPLETED) {
                            $this->eventRecorder->record(
                                $conversation,
                                ConversationEventType::VALUATION_COMPLETED,
                                [
                                    'snapshot_hash' => $snapshotHash,
                                    'status' => 'COMPLETED',
                                    'result' => $valuation->result ?? [],
                                ],
                                idempotencyKey: "val:{$snapshotHash}:reemit:completed:{$actionId}",
                                correlationId: $actionId
                            );
                        }

                        if ($valuation?->status === ValuationStatus::FAILED) {
                            $failure = is_array($valuation->result) ? $valuation->result : [];

                            $this->eventRecorder->record(
                                $conversation,
                                ConversationEventType::VALUATION_FAILED,
                                [
                                    'snapshot_hash' => $snapshotHash,
                                    'error' => $failure['error'] ?? 'Valuation failed',
                                    'error_code' => $failure['error_code'] ?? 'COMPUTATION_ERROR',
                                ],
                                idempotencyKey: "val:{$snapshotHash}:reemit:failed:{$actionId}",
                                correlationId: $actionId
                            );
                        }
                    }

                    return;
                }

                $this->eventRecorder->record(
                    $conversation,
                    ConversationEventType::APPRAISAL_CANCELLED,
                    [
                        'reason' => 'user_cancelled',
                        'source_message_event_id' => null,
                    ],
                    idempotencyKey: $actionId,
                    correlationId: $actionId
                );

                $this->eventRecorder->record(
                    $conversation,
                    ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                    ['content' => self::CANCELLATION_FOLLOW_UP_MESSAGE],
                    idempotencyKey: "{$actionId}:assistant.followup",
                    correlationId: $actionId
                );
            });

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->turnLifecycle->recordCompleted($conversation, $actionId, 'appraisal.confirm', $latencyMs);
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->turnLifecycle->recordFailed($conversation, $actionId, 'appraisal.confirm', $latencyMs, $e);
            throw $e;
        }

        $conversation->refresh();

        return response()->json([
            'ok' => true,
            'last_event_id' => $conversation->last_event_id,
        ]);
    }
}
