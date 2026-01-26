<?php

namespace App\Projectors;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Enums\ValuationStatus;
use App\Events\Conversation\ConversationEventRecorded;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
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

            ConversationEventType::APPRAISAL_QUESTION_ASKED => $this->projectAppraisalQuestionAsked($event),
            ConversationEventType::APPRAISAL_CONFIRMATION_REQUESTED => $this->projectAppraisalConfirmationRequested($event),
            ConversationEventType::APPRAISAL_CONFIRMED => $this->projectAppraisalConfirmed($event),

            ConversationEventType::VALUATION_REQUESTED => $this->projectValuationRequested($event),
            ConversationEventType::VALUATION_COMPLETED => $this->projectValuationCompleted($event),
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
     * Project appraisal.question.asked - enters APPRAISAL_INTAKE.
     */
    protected function projectAppraisalQuestionAsked(ConversationEvent $event): void
    {
        $conversation = Conversation::find($event->conversation_id);

        if ($conversation && $conversation->state === ConversationState::CHAT) {
            $conversation->update([
                'state' => ConversationState::APPRAISAL_INTAKE,
            ]);
        }
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
                'context' => array_merge(
                    $conversation->context ?? [],
                    ['appraisal' => $event->payload['appraisal'] ?? []]
                ),
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
     * Project a valuation requested event.
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

        // Create or find existing valuation
        $inputSnapshot = $event->payload;
        $snapshotHash = Valuation::generateSnapshotHash($inputSnapshot);

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

        // Find and update the valuation
        // We look for the most recent pending/running valuation for this conversation
        $valuation = Valuation::where('conversation_id', $event->conversation_id)
            ->whereIn('status', [ValuationStatus::PENDING, ValuationStatus::RUNNING])
            ->latest()
            ->first();

        if ($valuation) {
            $result = $event->payload['result'] ?? [];
            $valuation->markCompleted($result);
        }
    }
}
