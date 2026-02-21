<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\LeadIdentityConfirmRequest;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\ConversationEventRecorder;
use App\Services\ConversationOrchestrator;
use App\Services\TurnLifecycleRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LeadIdentityConfirmController extends Controller
{
    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder,
        private readonly TurnLifecycleRecorder $turnLifecycle
    ) {}

    /**
     * Confirm whether to reuse previously captured lead identity details.
     *
     * POST /api/widget/lead/confirm-identity
     */
    public function store(LeadIdentityConfirmRequest $request): JsonResponse
    {
        $clientId = $request->validated('client_id');
        $sessionToken = $request->validated('session_token');
        $actionId = $request->validated('action_id');
        $useExisting = (bool) $request->validated('use_existing');

        $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);

        if (!$conversation) {
            return response()->json([
                'error' => 'Invalid session token',
            ], 401);
        }

        $existingEvent = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('idempotency_key', $actionId)
            ->first();

        if ($existingEvent) {
            $conversation->refresh();

            return response()->json([
                'ok' => true,
                'last_event_id' => $conversation->last_event_id,
            ]);
        }

        if ($conversation->state !== ConversationState::LEAD_IDENTITY_CONFIRM) {
            return response()->json([
                'error' => 'Conversation is not awaiting lead identity confirmation',
            ], 409);
        }

        $candidate = is_array($conversation->lead_identity_candidate)
            ? $conversation->lead_identity_candidate
            : null;

        if (!$this->hasValidIdentityCandidate($candidate)) {
            return response()->json([
                'error' => 'No valid lead identity candidate found',
            ], 409);
        }

        $startedAt = microtime(true);
        $this->turnLifecycle->recordStarted($conversation, $actionId, 'lead.confirm_identity');

        try {
            DB::transaction(function () use ($conversation, $actionId, $useExisting, $candidate) {
                $this->eventRecorder->record(
                    $conversation,
                    ConversationEventType::LEAD_IDENTITY_DECISION_RECORDED,
                    [
                        'use_existing' => $useExisting,
                        'previous_lead_id' => $candidate['previous_lead_id'] ?? null,
                    ],
                    idempotencyKey: $actionId,
                    correlationId: $actionId
                );

                if ($useExisting) {
                    $this->eventRecorder->record(
                        $conversation,
                        ConversationEventType::LEAD_REQUESTED,
                        [
                            'name' => $candidate['name'],
                            'email' => $candidate['email'],
                            'phone_raw' => $candidate['phone_raw'],
                            'phone_normalized' => $candidate['phone_normalized'],
                            'previous_lead_id' => $candidate['previous_lead_id'] ?? null,
                            'source' => 'reused_previous_lead',
                        ],
                        idempotencyKey: "{$actionId}:lead.requested",
                        correlationId: $actionId
                    );

                    $messages = ConversationOrchestrator::leadSubmissionAssistantMessages();

                    $this->eventRecorder->record(
                        $conversation,
                        ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                        ['content' => $messages[0]],
                        idempotencyKey: "{$actionId}:assistant.lead.confirmed",
                        correlationId: $actionId
                    );
                    $this->eventRecorder->record(
                        $conversation,
                        ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                        ['content' => $messages[1]],
                        idempotencyKey: "{$actionId}:assistant.lead.confirmed.followup",
                        correlationId: $actionId
                    );

                    return;
                }

                $this->eventRecorder->record(
                    $conversation,
                    ConversationEventType::LEAD_STARTED,
                    [
                        'reason' => 'lead_identity_declined',
                        'source' => 'identity_confirmation',
                        'previous_lead_id' => $candidate['previous_lead_id'] ?? null,
                    ],
                    idempotencyKey: "{$actionId}:lead.started",
                    correlationId: $actionId
                );

                $namePrompt = ConversationOrchestrator::leadPromptForQuestion('name')
                    ?? 'Great, please share your full name.';

                $this->eventRecorder->record(
                    $conversation,
                    ConversationEventType::LEAD_QUESTION_ASKED,
                    [
                        'question_key' => 'name',
                        'label' => $namePrompt,
                        'required' => true,
                    ],
                    idempotencyKey: "{$actionId}:lead.question.name",
                    correlationId: $actionId
                );
                $this->eventRecorder->record(
                    $conversation,
                    ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                    ['content' => $namePrompt],
                    idempotencyKey: "{$actionId}:assistant.lead.question.name",
                    correlationId: $actionId
                );
            });

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->turnLifecycle->recordCompleted($conversation, $actionId, 'lead.confirm_identity', $latencyMs);
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->turnLifecycle->recordFailed($conversation, $actionId, 'lead.confirm_identity', $latencyMs, $e);
            throw $e;
        }

        $conversation->refresh();

        return response()->json([
            'ok' => true,
            'last_event_id' => $conversation->last_event_id,
        ]);
    }

    private function hasValidIdentityCandidate(?array $candidate): bool
    {
        if (!$candidate) {
            return false;
        }

        $required = ['name', 'email', 'phone_raw', 'phone_normalized'];

        foreach ($required as $key) {
            if (!isset($candidate[$key]) || trim((string) $candidate[$key]) === '') {
                return false;
            }
        }

        return true;
    }
}
