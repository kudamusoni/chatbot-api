<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\ValuationContactCaptureRequest;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Lead;
use App\Services\ConversationOrchestrator;
use App\Services\ConversationEventRecorder;
use App\Services\LeadPiiNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ValuationContactController extends Controller
{
    public function __construct(
        private readonly ConversationEventRecorder $eventRecorder,
        private readonly LeadPiiNormalizer $leadPiiNormalizer
    ) {}

    public function store(ValuationContactCaptureRequest $request): JsonResponse
    {
        $clientId = $request->header('X-Client-Id');
        $sessionToken = $request->header('X-Session-Token');

        if (!is_string($clientId) || trim($clientId) === '' || !is_string($sessionToken) || trim($sessionToken) === '') {
            return response()->json([
                'error' => 'Invalid session token',
            ], 401);
        }

        $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);

        if (!$conversation) {
            return response()->json([
                'error' => 'Invalid session token',
            ], 401);
        }

        $actionId = $request->validated('action_id');

        $email = (string) $request->validated('email');
        $name = (string) ($request->validated('name') ?? '');
        $phoneRaw = (string) ($request->validated('phone') ?? '');
        $phoneNormalized = $this->leadPiiNormalizer->normalizePhone($phoneRaw);
        $responseLeadId = null;

        try {
            DB::transaction(function () use (
                $conversation,
                $actionId,
                $email,
                $name,
                $phoneRaw,
                $phoneNormalized,
                &$responseLeadId
            ): void {
                $lockedConversation = Conversation::query()
                    ->where('id', $conversation->id)
                    ->where('client_id', $conversation->client_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $alreadyLinkedLeadId = is_string($lockedConversation->valuation_contact_lead_id)
                    ? trim($lockedConversation->valuation_contact_lead_id)
                    : '';
                if ($alreadyLinkedLeadId !== '') {
                    $responseLeadId = $alreadyLinkedLeadId;

                    return;
                }

                $existingEvent = ConversationEvent::query()
                    ->where('conversation_id', $lockedConversation->id)
                    ->where('idempotency_key', $actionId)
                    ->first();

                if ($existingEvent && $existingEvent->type === ConversationEventType::VALUATION_CONTACT_CAPTURED) {
                    $leadId = (string) ($existingEvent->payload['lead_id'] ?? '');
                    if ($leadId !== '') {
                        $lockedConversation->update([
                            'valuation_contact_lead_id' => $leadId,
                            'state' => ConversationState::APPRAISAL_CONFIRM,
                        ]);
                        $responseLeadId = $leadId;
                    }

                    return;
                }

                if ($lockedConversation->state !== ConversationState::VALUATION_CONTACT_CAPTURE) {
                    throw new \RuntimeException('INVALID_STATE_FOR_CONTACT_CAPTURE');
                }

                $lead = Lead::query()->firstOrCreate(
                    [
                        'client_id' => $lockedConversation->client_id,
                        'conversation_id' => $lockedConversation->id,
                        'lead_capture_action_id' => $actionId,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'request_event_id' => null,
                        'name' => $name,
                        'email' => $email,
                        'email_hash' => $this->leadPiiNormalizer->emailHash($email),
                        'phone_raw' => $phoneRaw,
                        'phone_normalized' => $phoneNormalized,
                        'phone_hash' => $phoneNormalized !== '' ? $this->leadPiiNormalizer->phoneHash($phoneRaw) : null,
                        'status' => 'REQUESTED',
                    ]
                );

                $lockedConversation->update([
                    'valuation_contact_lead_id' => $lead->id,
                    'state' => ConversationState::APPRAISAL_CONFIRM,
                ]);

                $responseLeadId = (string) $lead->id;
                $context = is_array($lockedConversation->context) ? $lockedConversation->context : [];
                $pendingIntent = isset($context['pending_intent']) && is_string($context['pending_intent'])
                    ? trim((string) $context['pending_intent'])
                    : null;

                // Emit capture event only when this action created a new lead row.
                if ($lead->wasRecentlyCreated) {
                    $this->eventRecorder->record(
                        $lockedConversation,
                        ConversationEventType::VALUATION_CONTACT_CAPTURED,
                        [
                            'lead_id' => $lead->id,
                            'email' => $email,
                            'email_hash' => $this->leadPiiNormalizer->emailHash($email),
                            'name' => $name,
                            'phone_raw' => $phoneRaw,
                            'phone_normalized' => $phoneNormalized,
                            'captured_at' => now()->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                            'source' => 'WIDGET_GATE',
                            'action_id' => $actionId,
                        ],
                        idempotencyKey: $actionId,
                        correlationId: $actionId
                    );
                }

                if ($pendingIntent === 'expert_review') {
                    $leadRequested = $this->eventRecorder->record(
                        $lockedConversation,
                        ConversationEventType::LEAD_REQUESTED,
                        [
                            'lead_id' => (string) $lead->id,
                            'name' => (string) ($lead->name ?? ''),
                            'email' => (string) ($lead->email ?? ''),
                            'phone_raw' => (string) ($lead->phone_raw ?? ''),
                            'phone_normalized' => (string) ($lead->phone_normalized ?? ''),
                            'source' => 'WIDGET_GATE',
                            'source_message_event_id' => null,
                        ],
                        idempotencyKey: "{$actionId}:lead.requested.expert_review",
                        correlationId: $actionId
                    );

                    if ($leadRequested['created']) {
                        $messages = ConversationOrchestrator::leadSubmissionAssistantMessages();

                        $this->eventRecorder->record(
                            $lockedConversation,
                            ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                            ['content' => $messages[0]],
                            idempotencyKey: "{$actionId}:assistant.expert_review.submitted",
                            correlationId: $actionId
                        );
                        $this->eventRecorder->record(
                            $lockedConversation,
                            ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                            ['content' => $messages[1]],
                            idempotencyKey: "{$actionId}:assistant.expert_review.followup",
                            correlationId: $actionId
                        );
                    }
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INVALID_STATE_FOR_CONTACT_CAPTURE') {
                return response()->json([
                    'error' => 'CONFLICT',
                    'reason_code' => 'INVALID_STATE_FOR_CONTACT_CAPTURE',
                ], 409);
            }

            throw $e;
        }

        $conversation->refresh();

        return response()->json([
            'ok' => true,
            'lead_id' => $responseLeadId,
            'last_event_id' => (int) ($conversation->last_event_id ?? 0),
        ]);
    }
}
