<?php

namespace App\Projectors;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Enums\ValuationStatus;
use App\Events\Conversation\ConversationEventRecorded;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\Valuation;

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
            ConversationEventType::APPRAISAL_CONFIRMATION_REQUESTED => $this->projectAppraisalConfirmationRequested($event),
            ConversationEventType::APPRAISAL_CONFIRMED => $this->projectAppraisalConfirmed($event),
            ConversationEventType::APPRAISAL_CANCELLED => $this->projectAppraisalCancelled($event),

            ConversationEventType::LEAD_STARTED => $this->projectLeadStarted($event),
            ConversationEventType::LEAD_IDENTITY_CONFIRMATION_REQUESTED => $this->projectLeadIdentityConfirmationRequested($event),
            ConversationEventType::LEAD_IDENTITY_DECISION_RECORDED => $this->projectLeadIdentityDecisionRecorded($event),
            ConversationEventType::LEAD_QUESTION_ASKED => $this->projectLeadQuestionAsked($event),
            ConversationEventType::LEAD_ANSWER_RECORDED => $this->projectLeadAnswerRecorded($event),
            ConversationEventType::LEAD_REQUESTED => $this->projectLeadRequested($event),

            ConversationEventType::VALUATION_REQUESTED => $this->projectValuationRequested($event),
            ConversationEventType::VALUATION_COMPLETED => $this->projectValuationCompleted($event),
            ConversationEventType::VALUATION_FAILED => $this->projectValuationFailed($event),
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

        Lead::firstOrCreate(
            ['request_event_id' => $event->id],
            [
                'conversation_id' => $event->conversation_id,
                'client_id' => $event->client_id,
                'name' => $event->payload['name'] ?? '',
                'email' => $event->payload['email'] ?? '',
                'phone_raw' => $event->payload['phone_raw'] ?? '',
                'phone_normalized' => $event->payload['phone_normalized'] ?? '',
                'status' => 'REQUESTED',
            ]
        );

        $conversation->update([
            'state' => ConversationState::CHAT,
            'lead_current_key' => null,
            'lead_answers' => null,
            'lead_identity_candidate' => null,
        ]);
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

        // Update conversation state
        $conversation->update([
            'state' => ConversationState::VALUATION_RUNNING,
        ]);

        // Handle both new structured payload and legacy flat payload
        if (isset($event->payload['input_snapshot'])) {
            // New structured format
            $inputSnapshot = $event->payload['input_snapshot'];
            $snapshotHash = $event->payload['snapshot_hash']
                ?? Valuation::generateSnapshotHash($inputSnapshot);
        } else {
            // Legacy flat format (payload IS the snapshot)
            $inputSnapshot = $event->payload;
            $snapshotHash = Valuation::generateSnapshotHash($inputSnapshot);
        }

        // Use firstOrCreate to handle idempotency
        Valuation::firstOrCreate(
            [
                'conversation_id' => $event->conversation_id,
                'snapshot_hash' => $snapshotHash,
            ],
            [
                'client_id' => $event->client_id,
                'request_event_id' => $event->id,
                'status' => ValuationStatus::PENDING,
                'input_snapshot' => $inputSnapshot,
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
                ->where('snapshot_hash', $snapshotHash)
                ->first();
        }

        if ($valuation && !$valuation->status->isTerminal()) {
            $result = $event->payload['result'] ?? [];
            $valuation->markCompleted($result);
        }
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
