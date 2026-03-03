<?php

namespace App\Projectors;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Enums\ValuationStatus;
use App\Events\Conversation\ConversationEventRecorded;
use App\Mail\LeadRequestedMail;
use App\Mail\ValuationCompletedMail;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\User;
use App\Models\Valuation;
use App\Services\LeadPiiNormalizer;
use Illuminate\Support\Facades\Mail;

/**
 * Projects conversation events into read-optimized tables.
 *
 * All projections are idempotent - safe to replay or retry.
 *
 * Runs synchronously for now. In Step 2/3, consider making this
 * queued for long-running operations.
 *
 * Listens to ConversationEventRecorded and updates:
 * - conversations (state, last_event_id, last_activity_at)
 * - conversation_messages (for message events)
 * - valuations (for valuation events)
 */
class ConversationProjector
{
    private LeadPiiNormalizer $leadPiiNormalizer;

    public function __construct(
        ?LeadPiiNormalizer $leadPiiNormalizer = null
    ) {
        $this->leadPiiNormalizer = $leadPiiNormalizer ?? new LeadPiiNormalizer();
    }

    /**
     * Handle the event.
     */
    public function handle(ConversationEventRecorded $eventRecorded): void
    {
        $event = $eventRecorded->event;

        // Always update the conversation projection
        $this->updateConversationProjection($event);

        // Route to specific handler based on event type
        match ($event->type) {
            ConversationEventType::USER_MESSAGE_CREATED,
            ConversationEventType::ASSISTANT_MESSAGE_CREATED => $this->projectMessage($event),

            ConversationEventType::APPRAISAL_STARTED => $this->projectAppraisalStarted($event),
            ConversationEventType::APPRAISAL_QUESTION_ASKED => $this->projectAppraisalQuestionAsked($event),
            ConversationEventType::APPRAISAL_ANSWER_RECORDED => $this->projectAppraisalAnswerRecorded($event),
            ConversationEventType::APPRAISAL_ANSWER_NORMALIZATION_REQUESTED => null,
            ConversationEventType::APPRAISAL_ANSWER_NORMALIZATION_COMPLETED => $this->projectAppraisalNormalizationCompleted($event),
            ConversationEventType::APPRAISAL_ANSWER_NORMALIZATION_FAILED => $this->projectAppraisalNormalizationFailed($event),
            ConversationEventType::APPRAISAL_PREFLIGHT_FAILED => $this->projectAppraisalPreflightFailed($event),
            ConversationEventType::APPRAISAL_PREFLIGHT_PASSED => null,
            ConversationEventType::APPRAISAL_CONFIRMATION_REQUESTED => $this->projectAppraisalConfirmationRequested($event),
            ConversationEventType::APPRAISAL_CONFIRMED => $this->projectAppraisalConfirmed($event),
            ConversationEventType::APPRAISAL_CANCELLED => $this->projectAppraisalCancelled($event),

            ConversationEventType::LEAD_STARTED => $this->projectLeadStarted($event),
            ConversationEventType::LEAD_IDENTITY_CONFIRMATION_REQUESTED => $this->projectLeadIdentityConfirmationRequested($event),
            ConversationEventType::LEAD_IDENTITY_DECISION_RECORDED => $this->projectLeadIdentityDecisionRecorded($event),
            ConversationEventType::LEAD_QUESTION_ASKED => $this->projectLeadQuestionAsked($event),
            ConversationEventType::LEAD_ANSWER_RECORDED => $this->projectLeadAnswerRecorded($event),
            ConversationEventType::LEAD_REQUESTED => $this->projectLeadRequested($event),

            ConversationEventType::VALUATION_CONTACT_REQUESTED => $this->projectValuationContactRequested($event),
            ConversationEventType::VALUATION_CONTACT_CAPTURED => $this->projectValuationContactCaptured($event),
            ConversationEventType::VALUATION_REQUESTED => $this->projectValuationRequested($event),
            ConversationEventType::VALUATION_COMPLETED => $this->projectValuationCompleted($event),
            ConversationEventType::VALUATION_FAILED => $this->projectValuationFailed($event),

            ConversationEventType::ASSISTANT_RESPONSE_REQUESTED => null,
            ConversationEventType::ASSISTANT_RESPONSE_COMPLETED => $this->projectAssistantResponseCompleted($event),
            ConversationEventType::ASSISTANT_RESPONSE_FAILED => $this->projectAssistantResponseFailed($event),

            ConversationEventType::TURN_STARTED,
            ConversationEventType::TURN_COMPLETED,
            ConversationEventType::TURN_FAILED => null,
        };
    }

    /**
     * Update the conversation projection (called for every event).
     */
    protected function updateConversationProjection(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if (!$conversation) {
            return;
        }

        $conversation->update([
            'last_event_id' => $event->id,
            'last_activity_at' => $event->created_at,
        ]);
    }

    /**
     * Project a message event into conversation_messages.
     *
     * Idempotent via unique(conversation_id, event_id) constraint.
     */
    protected function projectMessage(ConversationEvent $event): void
    {
        $content = $event->payload['content'] ?? '';

        // Idempotent: each event can only create one message
        ConversationMessage::firstOrCreate(
            [
                'conversation_id' => $event->conversation_id,
                'event_id' => $event->id,
            ],
            [
                'client_id' => $event->client_id,
                'role' => $event->messageRole(),
                'content' => $content,
                'turn_id' => $event->payload['turn_id'] ?? null,
            ]
        );
    }

    /**
     * Project appraisal.started - enters APPRAISAL_INTAKE.
     */
    protected function projectAppraisalStarted(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if ($conversation) {
            $conversation->update([
                'state' => ConversationState::APPRAISAL_INTAKE,
                'appraisal_answers' => [],
                'appraisal_current_key' => null,
                'appraisal_snapshot' => null,
                'appraisal_snapshot_normalized' => null,
                'normalization_meta' => null,
                'last_ai_error_code' => null,
                'valuation_contact_lead_id' => null,
            ]);
        }
    }

    /**
     * Project appraisal.question.asked - enters APPRAISAL_INTAKE.
     */
    protected function projectAppraisalQuestionAsked(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if ($conversation) {
            $conversation->update([
                'state' => ConversationState::APPRAISAL_INTAKE,
                'appraisal_current_key' => $event->payload['question_key'] ?? null,
            ]);
        }
    }

    /**
     * Project appraisal.answer.recorded - stores answer.
     */
    protected function projectAppraisalAnswerRecorded(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if (!$conversation) {
            return;
        }

        $answers = $conversation->appraisal_answers ?? [];
        $questionKey = $event->payload['question_key'] ?? null;

        if ($questionKey) {
            $answers[$questionKey] = $event->payload['value'] ?? null;
        }

        $conversation->update([
            'appraisal_answers' => $answers,
            'appraisal_current_key' => null,
        ]);
    }

    protected function projectAppraisalNormalizationCompleted(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);
        if (!$conversation) {
            return;
        }

        $questionKey = (string) ($event->payload['question_key'] ?? '');
        if ($questionKey === '') {
            return;
        }

        $normalizedSnapshot = is_array($conversation->appraisal_snapshot_normalized)
            ? $conversation->appraisal_snapshot_normalized
            : [];
        $normalizationMeta = is_array($conversation->normalization_meta)
            ? $conversation->normalization_meta
            : [];

        $normalizedSnapshot[$questionKey] = $event->payload['normalized'] ?? null;
        $normalizationMeta[$questionKey] = [
            'confidence' => (float) ($event->payload['confidence'] ?? 0),
            'candidates' => is_array($event->payload['candidates'] ?? null) ? $event->payload['candidates'] : [],
            'needs_clarification' => (bool) ($event->payload['needs_clarification'] ?? false),
            'clarifying_question' => $event->payload['clarifying_question'] ?? null,
            'user_skipped' => (bool) ($event->payload['user_skipped'] ?? false),
            'unresolved' => (bool) ($event->payload['unresolved'] ?? false),
            'clarification_asked' => (bool) ($event->payload['clarification_asked'] ?? false),
        ];

        $conversation->update([
            'appraisal_snapshot_normalized' => $normalizedSnapshot,
            'normalization_meta' => $normalizationMeta,
            'last_ai_error_code' => null,
        ]);
    }

    protected function projectAppraisalNormalizationFailed(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);
        if (!$conversation) {
            return;
        }

        $conversation->update([
            'last_ai_error_code' => $event->payload['error_code'] ?? 'AI_NORMALIZATION_FAILED',
        ]);
    }

    protected function projectAppraisalPreflightFailed(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);
        if (!$conversation) {
            return;
        }

        $normalizationMeta = is_array($conversation->normalization_meta)
            ? $conversation->normalization_meta
            : [];
        $normalizationMeta['__preflight'] = [
            'status' => 'FAILED',
            'details' => is_array($event->payload['preflight_details'] ?? null) ? $event->payload['preflight_details'] : [],
            'missing_fields' => is_array($event->payload['missing_fields'] ?? null) ? $event->payload['missing_fields'] : [],
            'low_confidence_fields' => is_array($event->payload['low_confidence_fields'] ?? null) ? $event->payload['low_confidence_fields'] : [],
        ];

        $conversation->update([
            'state' => ConversationState::APPRAISAL_INTAKE,
            'appraisal_current_key' => $event->payload['next_question_key'] ?? null,
            'normalization_meta' => $normalizationMeta,
        ]);
    }

    protected function projectAssistantResponseCompleted(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);
        if (!$conversation) {
            return;
        }

        $conversation->update([
            'last_ai_error_code' => null,
        ]);
    }

    protected function projectAssistantResponseFailed(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);
        if (!$conversation) {
            return;
        }

        $conversation->update([
            'last_ai_error_code' => $event->payload['error_code'] ?? 'AI_ASSISTANT_FAILED',
        ]);
    }

    /**
     * Project appraisal.confirmation.requested - shows confirmation panel.
     * State: APPRAISAL_CONFIRM
     */
    protected function projectAppraisalConfirmationRequested(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if ($conversation) {
            $conversation->update([
                'state' => ConversationState::APPRAISAL_CONFIRM,
                'appraisal_snapshot' => $event->payload['snapshot'] ?? [],
                'appraisal_current_key' => null,
            ]);
        }
    }

    /**
     * Project appraisal.confirmed - user confirmed, ready for valuation.
     * State: VALUATION_RUNNING (triggers valuation job)
     */
    protected function projectAppraisalConfirmed(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if ($conversation) {
            $conversation->update([
                'state' => ConversationState::VALUATION_RUNNING,
            ]);
        }
    }

    /**
     * Project appraisal.cancelled - exits appraisal flow.
     */
    protected function projectAppraisalCancelled(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if ($conversation) {
            $conversation->update([
                'state' => ConversationState::CHAT,
                'appraisal_current_key' => null,
                'appraisal_snapshot' => null,
                'appraisal_snapshot_normalized' => null,
                'normalization_meta' => null,
                'last_ai_error_code' => null,
                'valuation_contact_lead_id' => null,
            ]);
        }
    }

    /**
     * Project lead.started - enters leads intake.
     */
    protected function projectLeadStarted(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if ($conversation) {
            $conversation->update([
                'state' => ConversationState::LEAD_INTAKE,
                'lead_answers' => [],
                'lead_current_key' => null,
                'lead_identity_candidate' => null,
            ]);
        }
    }

    /**
     * Project lead.identity.confirmation.requested - enters identity confirmation state.
     */
    protected function projectLeadIdentityConfirmationRequested(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if ($conversation) {
            $conversation->update([
                'state' => ConversationState::LEAD_IDENTITY_CONFIRM,
                'lead_identity_candidate' => [
                    'previous_lead_id' => $event->payload['previous_lead_id'] ?? null,
                    'name' => $event->payload['name'] ?? null,
                    'email' => $event->payload['email'] ?? null,
                    'phone_raw' => $event->payload['phone_raw'] ?? null,
                    'phone_normalized' => $event->payload['phone_normalized'] ?? null,
                ],
                'lead_answers' => null,
                'lead_current_key' => null,
            ]);
        }
    }

    /**
     * Project lead.identity.decision.recorded.
     */
    protected function projectLeadIdentityDecisionRecorded(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if (!$conversation) {
            return;
        }

        $useExisting = (bool) ($event->payload['use_existing'] ?? false);

        if ($useExisting) {
            return;
        }

        $conversation->update([
            'lead_identity_candidate' => null,
        ]);
    }

    /**
     * Project lead.question.asked - tracks active question.
     */
    protected function projectLeadQuestionAsked(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if ($conversation) {
            $conversation->update([
                'state' => ConversationState::LEAD_INTAKE,
                'lead_current_key' => $event->payload['question_key'] ?? null,
            ]);
        }
    }

    /**
     * Project lead.answer.recorded - stores answer.
     */
    protected function projectLeadAnswerRecorded(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if (!$conversation) {
            return;
        }

        $answers = $conversation->lead_answers ?? [];
        $questionKey = $event->payload['question_key'] ?? null;

        if ($questionKey) {
            $answers[$questionKey] = $event->payload['value'] ?? null;
        }

        $conversation->update([
            'lead_answers' => $answers,
            'lead_current_key' => null,
        ]);
    }

    /**
     * Project lead.requested - create request row and return to chat.
     */
    protected function projectLeadRequested(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if (!$conversation) {
            return;
        }

        $email = (string) ($event->payload['email'] ?? '');
        $phoneRaw = (string) ($event->payload['phone_raw'] ?? '');
        $phoneNormalized = $this->leadPiiNormalizer->normalizePhone($phoneRaw);

        $lead = null;
        $shouldNotify = false;
        $providedLeadId = $event->payload['lead_id'] ?? null;

        if (is_string($providedLeadId) && trim($providedLeadId) !== '') {
            $lead = Lead::query()
                ->where('id', $providedLeadId)
                ->where('conversation_id', $event->conversation_id)
                ->where('client_id', $event->client_id)
                ->first();

            if ($lead && $lead->request_event_id === null) {
                $lead->update([
                    'request_event_id' => $event->id,
                    'name' => $event->payload['name'] ?? $lead->name,
                    'email' => $email !== '' ? $email : $lead->email,
                    'email_hash' => $email !== '' ? $this->leadPiiNormalizer->emailHash($email) : $lead->email_hash,
                    'phone_raw' => $phoneRaw !== '' ? $phoneRaw : $lead->phone_raw,
                    'phone_normalized' => $phoneNormalized !== '' ? $phoneNormalized : $lead->phone_normalized,
                    'phone_hash' => $phoneNormalized !== '' ? $this->leadPiiNormalizer->phoneHash($phoneRaw) : $lead->phone_hash,
                ]);
                $shouldNotify = true;
            }
        }

        if (!$lead) {
            $lead = Lead::firstOrCreate(
                ['request_event_id' => $event->id],
                [
                    'conversation_id' => $event->conversation_id,
                    'client_id' => $event->client_id,
                    'name' => $event->payload['name'] ?? '',
                    'email' => $email,
                    'email_hash' => $email !== '' ? $this->leadPiiNormalizer->emailHash($email) : null,
                    'phone_raw' => $phoneRaw,
                    'phone_normalized' => $phoneNormalized,
                    'phone_hash' => $phoneNormalized !== '' ? $this->leadPiiNormalizer->phoneHash($phoneRaw) : null,
                    'status' => 'REQUESTED',
                ]
            );
            $shouldNotify = $lead->wasRecentlyCreated;
        }

        if ($lead && $shouldNotify) {
            $this->notifyClientUsersOfLead($lead);
        }

        $context = is_array($conversation->context) ? $conversation->context : [];
        unset($context['pending_intent']);

        $conversation->update([
            'state' => ConversationState::CHAT,
            'lead_current_key' => null,
            'lead_answers' => null,
            'lead_identity_candidate' => null,
            'context' => $context,
        ]);
    }

    protected function projectValuationContactRequested(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if (!$conversation) {
            return;
        }

        $context = is_array($conversation->context) ? $conversation->context : [];
        $pendingIntent = $event->payload['pending_intent'] ?? null;
        if (is_string($pendingIntent) && $pendingIntent !== '') {
            $context['pending_intent'] = $pendingIntent;
        } else {
            unset($context['pending_intent']);
        }

        $conversation->update([
            'state' => ConversationState::VALUATION_CONTACT_CAPTURE,
            'context' => $context,
        ]);
    }

    protected function projectValuationContactCaptured(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if (!$conversation) {
            return;
        }

        $email = (string) ($event->payload['email'] ?? '');
        $phoneRaw = (string) ($event->payload['phone_raw'] ?? '');
        $phoneNormalized = $this->leadPiiNormalizer->normalizePhone($phoneRaw);
        $leadId = (string) ($event->payload['lead_id'] ?? '');
        $leadCaptureActionId = (string) ($event->payload['action_id'] ?? '');

        if ($leadId === '' || $leadCaptureActionId === '') {
            return;
        }

        Lead::firstOrCreate(
            [
                'client_id' => $event->client_id,
                'conversation_id' => $event->conversation_id,
                'lead_capture_action_id' => $leadCaptureActionId,
            ],
            [
                'id' => $leadId,
                'request_event_id' => $event->id,
                'name' => (string) ($event->payload['name'] ?? ''),
                'email' => $email,
                'email_hash' => $email !== '' ? $this->leadPiiNormalizer->emailHash($email) : null,
                'phone_raw' => $phoneRaw,
                'phone_normalized' => $phoneNormalized,
                'phone_hash' => $phoneNormalized !== '' ? $this->leadPiiNormalizer->phoneHash($phoneRaw) : null,
                'status' => 'REQUESTED',
            ]
        );

        $context = is_array($conversation->context) ? $conversation->context : [];
        $pendingIntent = isset($context['pending_intent']) && is_string($context['pending_intent'])
            ? trim((string) $context['pending_intent'])
            : null;

        $conversation->update([
            'state' => $pendingIntent === 'expert_review'
                ? ConversationState::VALUATION_CONTACT_CAPTURE
                : ConversationState::APPRAISAL_CONFIRM,
            'valuation_contact_lead_id' => $leadId,
        ]);
    }

    private function notifyClientUsersOfLead(Lead $lead): void
    {
        $clientName = (string) ($lead->client?->name ?? 'your company');
        $dashboardBase = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $dashboardUrl = "{$dashboardBase}/leads/{$lead->id}";

        $recipients = User::query()
            ->join('client_user', 'client_user.user_id', '=', 'users.id')
            ->where('client_user.client_id', $lead->client_id)
            ->whereNotNull('users.email')
            ->distinct()
            ->pluck('users.email')
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->values();

        foreach ($recipients as $email) {
            Mail::to((string) $email)->queue(new LeadRequestedMail($clientName, $lead, $dashboardUrl));
        }
    }

    /**
     * Project a valuation requested event.
     *
     * Handles both new structured payload and legacy flat payload formats.
     */
    protected function projectValuationRequested(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if (!$conversation) {
            return;
        }

        $leadId = $event->payload['lead_id'] ?? null;
        if (!is_string($leadId) || trim($leadId) === '') {
            throw new \RuntimeException('valuation.requested missing lead_id');
        }

        // Update conversation state
        $conversation->update([
            'state' => ConversationState::VALUATION_RUNNING,
        ]);

        // Handle both new structured payload and legacy flat payload
        if (isset($event->payload['input_snapshot'])) {
            // New structured format
            $inputSnapshot = Valuation::normalizeSnapshotForStorage(
                is_array($event->payload['input_snapshot']) ? $event->payload['input_snapshot'] : []
            );
            $snapshotHash = $event->payload['snapshot_hash']
                ?? Valuation::generateSnapshotHash($inputSnapshot);
        } else {
            // Legacy flat format (payload IS the snapshot)
            $legacyPayload = is_array($event->payload) ? $event->payload : [];
            unset(
                $legacyPayload['lead_id'],
                $legacyPayload['snapshot_hash'],
                $legacyPayload['conversation_id'],
                $legacyPayload['preflight_status'],
                $legacyPayload['preflight_details'],
                $legacyPayload['confidence_cap'],
                $legacyPayload['retry']
            );
            $inputSnapshot = Valuation::normalizeSnapshotForStorage(
                $legacyPayload
            );
            $snapshotHash = Valuation::generateSnapshotHash($inputSnapshot);
        }

        // Use firstOrCreate to handle idempotency
        Valuation::firstOrCreate(
            [
                'conversation_id' => $event->conversation_id,
                'client_id' => $event->client_id,
                'snapshot_hash' => $snapshotHash,
            ],
            [
                'request_event_id' => $event->id,
                'lead_id' => $leadId,
                'status' => ValuationStatus::PENDING,
                'input_snapshot' => $inputSnapshot,
                'preflight_status' => $event->payload['preflight_status'] ?? null,
                'preflight_details' => is_array($event->payload['preflight_details'] ?? null)
                    ? $event->payload['preflight_details']
                    : null,
                'confidence_cap' => isset($event->payload['confidence_cap'])
                    ? (float) $event->payload['confidence_cap']
                    : null,
            ]
        );
    }

    /**
     * Project a valuation completed event.
     *
     * Finds valuation by snapshot_hash (replay-safe) rather than "latest pending".
     */
    protected function projectValuationCompleted(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if (!$conversation) {
            return;
        }

        // Update conversation state
        $conversation->update([
            'state' => ConversationState::VALUATION_READY,
        ]);

        // Find valuation by snapshot_hash (replay-safe lookup)
        $snapshotHash = $event->payload['snapshot_hash'] ?? null;

        if (!$snapshotHash) {
            // Fallback to legacy behavior for old events without snapshot_hash
            $valuation = Valuation::where('conversation_id', $event->conversation_id)
                ->whereIn('status', [ValuationStatus::PENDING, ValuationStatus::RUNNING])
                ->latest()
                ->first();
        } else {
            $valuation = Valuation::where('conversation_id', $event->conversation_id)
                ->where('client_id', $event->client_id)
                ->where('snapshot_hash', $snapshotHash)
                ->first();
        }

        if ($valuation && !$valuation->status->isTerminal()) {
            $result = $event->payload['result'] ?? [];
            $valuation->markCompleted($result);
            $this->notifyLeadOfValuationCompleted($valuation, is_array($result) ? $result : []);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function notifyLeadOfValuationCompleted(Valuation $valuation, array $result): void
    {
        $lead = $valuation->lead;
        if (!$lead) {
            return;
        }

        $email = is_string($lead->email) ? trim($lead->email) : '';
        if ($email === '') {
            return;
        }

        $clientName = (string) ($valuation->client?->name ?? 'your company');
        Mail::to($email)->queue(new ValuationCompletedMail($clientName, $lead, $valuation, $result));
    }

    /**
     * Project a valuation failed event.
     *
     * Sets conversation to VALUATION_FAILED state so UI can show retry option.
     */
    protected function projectValuationFailed(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if (!$conversation) {
            return;
        }

        // Set to VALUATION_FAILED state - UI can show retry option
        $conversation->update([
            'state' => ConversationState::VALUATION_FAILED,
        ]);

        // Find and update valuation by snapshot_hash
        $snapshotHash = $event->payload['snapshot_hash'] ?? null;

        if ($snapshotHash) {
            $valuation = Valuation::where('conversation_id', $event->conversation_id)
                ->where('client_id', $event->client_id)
                ->where('snapshot_hash', $snapshotHash)
                ->first();

            if ($valuation && !$valuation->status->isTerminal()) {
                $valuation->markFailed([
                    'error' => $event->payload['error'] ?? 'Unknown error',
                    'error_code' => $event->payload['error_code'] ?? null,
                ]);
            }
        }
    }
}
