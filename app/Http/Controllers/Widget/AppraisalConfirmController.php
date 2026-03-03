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
use App\Services\AppraisalPreflightService;
use App\Services\AppraisalPreflightPayloadBuilder;
use App\Services\ConversationEventRecorder;
use App\Services\TurnLifecycleRecorder;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AppraisalConfirmController extends Controller
{
    private const CANCELLATION_FOLLOW_UP_MESSAGE = 'Is there anything else that you needed info about?';

    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder,
        private readonly TurnLifecycleRecorder $turnLifecycle,
        private readonly AppraisalPreflightService $preflightService,
        private readonly AppraisalPreflightPayloadBuilder $preflightPayloadBuilder
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

        if ($conversation->state === ConversationState::VALUATION_CONTACT_CAPTURE) {
            $contact = $this->valuationContactPrefill($conversation);
            return response()->json([
                'ok' => true,
                'blocked' => true,
                'reason_code' => 'VALUATION_CONTACT_REQUIRED',
                'pending_intent' => $this->pendingIntent($conversation),
                'lead_id' => $contact['lead_id'],
                'valuation_contact_prefill' => $contact['valuation_contact_prefill'],
                'last_event_id' => (int) ($conversation->last_event_id ?? 0),
            ]);
        }

        if (in_array($conversation->state, [ConversationState::VALUATION_RUNNING, ConversationState::VALUATION_READY], true)) {
            return response()->json([
                'ok' => true,
                'blocked' => false,
                'last_event_id' => (int) ($conversation->last_event_id ?? 0),
            ]);
        }

        // Check for idempotent retry - if action was already processed, return success
        $existingEvent = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('idempotency_key', $actionId)
            ->first();

        if ($existingEvent) {
            $wasBlocked = in_array($existingEvent->type, [
                ConversationEventType::APPRAISAL_PREFLIGHT_FAILED,
                ConversationEventType::VALUATION_CONTACT_REQUESTED,
            ], true);
            if ($wasBlocked) {
                if ($existingEvent->type === ConversationEventType::VALUATION_CONTACT_REQUESTED) {
                    $payload = is_array($existingEvent->payload) ? $existingEvent->payload : [];
                    $contact = $this->valuationContactPrefill($conversation);
                    return response()->json([
                        'ok' => true,
                        'blocked' => true,
                        'reason_code' => 'VALUATION_CONTACT_REQUIRED',
                        'pending_intent' => $payload['pending_intent'] ?? $this->pendingIntent($conversation),
                        'lead_id' => $payload['lead_id'] ?? $contact['lead_id'],
                        'valuation_contact_prefill' => is_array($payload['valuation_contact_prefill'] ?? null)
                            ? $payload['valuation_contact_prefill']
                            : $contact['valuation_contact_prefill'],
                        'last_event_id' => (int) ($conversation->last_event_id ?? 0),
                    ]);
                }

                return response()->json(
                    $this->preflightPayloadBuilder->blockedResponse((int) ($conversation->last_event_id ?? 0))
                );
            }

            $payload = [
                'ok' => true,
                'blocked' => false,
                'last_event_id' => $conversation->last_event_id,
            ];

            return response()->json($payload);
        }

        // Only allow confirm/cancel from APPRAISAL_CONFIRM state
        if ($conversation->state !== ConversationState::APPRAISAL_CONFIRM) {
            return response()->json([
                'error' => 'Conversation is not awaiting confirmation',
            ], 409);
        }

        $startedAt = microtime(true);
        $this->turnLifecycle->recordStarted($conversation, $actionId, 'appraisal.confirm');
        $blockedByPreflight = false;
        $blockedByContact = false;

        try {
            DB::transaction(function () use ($conversation, $confirm, $actionId, &$blockedByPreflight, &$blockedByContact) {
                if ($confirm) {
                    $rawSnapshot = is_array($conversation->appraisal_snapshot)
                        ? $conversation->appraisal_snapshot
                        : (is_array($conversation->appraisal_answers) ? $conversation->appraisal_answers : []);
                    $preflight = $this->preflightService->run(
                        (string) $conversation->client_id,
                        (string) $conversation->id,
                        $rawSnapshot
                    );

                    $snapshot = Valuation::normalizeSnapshotForStorage($preflight['snapshot']);
                    $normalizationMeta = is_array($snapshot['normalization_meta'] ?? null)
                        ? $snapshot['normalization_meta']
                        : [];
                    $normalizationMeta['__preflight'] = [
                        'status' => $preflight['preflight_status'],
                        'details' => $preflight['preflight_details'],
                        'confidence_cap' => $preflight['confidence_cap'],
                    ];
                    $snapshot['normalization_meta'] = $normalizationMeta;

                    $conversation->update([
                        'appraisal_snapshot_normalized' => $snapshot['normalized'],
                        'normalization_meta' => $snapshot['normalization_meta'],
                        'appraisal_snapshot' => $snapshot['raw'],
                    ]);

                    if (!$preflight['passed']) {
                        $blockedByPreflight = true;
                        $failedPayload = $this->preflightPayloadBuilder->failedPayload($preflight);
                        $message = $failedPayload['message'];
                        $nextQuestionKey = $failedPayload['next_question_key'];

                        $this->eventRecorder->record(
                            $conversation,
                            ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                            [
                                'content' => $message,
                            ],
                            idempotencyKey: "{$actionId}:preflight.failed.assistant",
                            correlationId: $actionId
                        );

                        $this->eventRecorder->record(
                            $conversation,
                            ConversationEventType::APPRAISAL_PREFLIGHT_FAILED,
                            $failedPayload,
                            idempotencyKey: $actionId,
                            correlationId: $actionId
                        );

                        if (is_string($nextQuestionKey) && $nextQuestionKey !== '') {
                            $this->eventRecorder->record(
                                $conversation,
                                ConversationEventType::APPRAISAL_QUESTION_ASKED,
                                [
                                    'question_key' => $nextQuestionKey,
                                    'required' => true,
                                ],
                                idempotencyKey: "{$actionId}:preflight.failed.question.{$nextQuestionKey}",
                                correlationId: $actionId
                            );
                        }

                        return;
                    }

                    $leadId = is_string($conversation->valuation_contact_lead_id)
                        ? trim($conversation->valuation_contact_lead_id)
                        : '';

                    if ($leadId === '') {
                        $blockedByContact = true;
                        $contact = $this->valuationContactPrefill($conversation);
                        $this->eventRecorder->record(
                            $conversation,
                            ConversationEventType::VALUATION_CONTACT_REQUESTED,
                            [
                                'reason' => 'VALUATION_REQUIRES_EMAIL',
                                'pending_intent' => 'valuation',
                                'lead_id' => $contact['lead_id'],
                                'valuation_contact_prefill' => $contact['valuation_contact_prefill'],
                                'fields_required' => ['email'],
                            ],
                            idempotencyKey: $actionId,
                            correlationId: $actionId
                        );

                        return;
                    }

                    $this->eventRecorder->record(
                        $conversation,
                        ConversationEventType::APPRAISAL_PREFLIGHT_PASSED,
                        $this->preflightPayloadBuilder->passedPayload($preflight),
                        idempotencyKey: "{$actionId}:preflight.passed",
                        correlationId: $actionId
                    );

                    $this->eventRecorder->record(
                        $conversation,
                        ConversationEventType::APPRAISAL_CONFIRMED,
                        [],
                        idempotencyKey: $actionId,
                        correlationId: $actionId
                    );

                    // Keep idempotency hash keyed to raw snapshot so replays
                    // match historical valuations created before preflight metadata.
                    $snapshotHash = Valuation::generateSnapshotHash(
                        is_array($snapshot['raw'] ?? null) ? $snapshot['raw'] : []
                    );

                    // Emit valuation.requested with structured payload
                    $requested = $this->eventRecorder->record(
                        $conversation,
                        ConversationEventType::VALUATION_REQUESTED,
                        [
                            'snapshot_hash' => $snapshotHash,
                            'input_snapshot' => $snapshot,
                            'conversation_id' => $conversation->id,
                            'lead_id' => $leadId,
                            'preflight_status' => $preflight['preflight_status'],
                            'preflight_details' => $preflight['preflight_details'],
                            'confidence_cap' => $preflight['confidence_cap'],
                        ],
                        idempotencyKey: $snapshotHash,
                        correlationId: $actionId
                    );

                    if (!$requested['created']) {
                        $valuation = Valuation::where('conversation_id', $conversation->id)
                            ->where('client_id', $conversation->client_id)
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

        $payload = [
            'ok' => true,
            'blocked' => $blockedByPreflight || $blockedByContact,
            'last_event_id' => $conversation->last_event_id,
        ];
        if ($blockedByPreflight) {
            $payload = $this->preflightPayloadBuilder->blockedResponse((int) ($conversation->last_event_id ?? 0));
        } elseif ($blockedByContact) {
            $contact = $this->valuationContactPrefill($conversation);
            $payload = [
                'ok' => true,
                'blocked' => true,
                'reason_code' => 'VALUATION_CONTACT_REQUIRED',
                'pending_intent' => $this->pendingIntent($conversation),
                'lead_id' => $contact['lead_id'],
                'valuation_contact_prefill' => $contact['valuation_contact_prefill'],
                'last_event_id' => (int) ($conversation->last_event_id ?? 0),
            ];
        }

        return response()->json($payload);
    }

    /**
     * @return array{lead_id:?string, valuation_contact_prefill:?array{name:?string,email:?string,phone:?string}}
     */
    private function valuationContactPrefill(Conversation $conversation): array
    {
        $lead = null;
        $capturedLeadId = is_string($conversation->valuation_contact_lead_id)
            ? trim($conversation->valuation_contact_lead_id)
            : '';

        if ($capturedLeadId !== '') {
            $lead = Lead::query()
                ->where('id', $capturedLeadId)
                ->where('client_id', $conversation->client_id)
                ->first();
        }

        if (!$lead) {
            $lead = Lead::query()
                ->where('conversation_id', $conversation->id)
                ->where('client_id', $conversation->client_id)
                ->latest('created_at')
                ->first();
        }

        if (!$lead) {
            return [
                'lead_id' => null,
                'valuation_contact_prefill' => null,
            ];
        }

        $email = is_string($lead->email) && trim($lead->email) !== ''
            ? trim($lead->email)
            : null;
        $name = is_string($lead->name) && trim($lead->name) !== ''
            ? trim($lead->name)
            : null;
        $phone = is_string($lead->phone_normalized) && trim($lead->phone_normalized) !== ''
            ? trim($lead->phone_normalized)
            : null;

        return [
            'lead_id' => (string) $lead->id,
            'valuation_contact_prefill' => [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
            ],
        ];
    }

    private function pendingIntent(Conversation $conversation): ?string
    {
        $context = is_array($conversation->context) ? $conversation->context : [];
        $pendingIntent = $context['pending_intent'] ?? null;

        return is_string($pendingIntent) && trim($pendingIntent) !== ''
            ? trim($pendingIntent)
            : null;
    }
}
