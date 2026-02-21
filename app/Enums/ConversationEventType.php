<?php

namespace App\Enums;

/**
 * Event types for conversation events.
 *
 * State transitions:
 * - CHAT: user.message.created, assistant.message.created
 * - APPRAISAL_INTAKE: appraisal.started, appraisal.question.asked (enters intake flow)
 * - APPRAISAL_CONFIRM: appraisal.confirmation.requested (show confirmation panel)
 * - VALUATION_RUNNING: appraisal.confirmed (user confirms), valuation.requested
 * - VALUATION_READY: valuation.completed
 */
enum ConversationEventType: string
{
    case USER_MESSAGE_CREATED = 'user.message.created';
    case ASSISTANT_MESSAGE_CREATED = 'assistant.message.created';

    // Appraisal flow
    case APPRAISAL_STARTED = 'appraisal.started';
    case APPRAISAL_QUESTION_ASKED = 'appraisal.question.asked';
    case APPRAISAL_ANSWER_RECORDED = 'appraisal.answer.recorded';
    case APPRAISAL_CONFIRMATION_REQUESTED = 'appraisal.confirmation.requested';
    case APPRAISAL_CONFIRMED = 'appraisal.confirmed';
    case APPRAISAL_CANCELLED = 'appraisal.cancelled';

    // Valuation flow
    case VALUATION_REQUESTED = 'valuation.requested';
    case VALUATION_COMPLETED = 'valuation.completed';
    case VALUATION_FAILED = 'valuation.failed';

    // Leads flow
    case LEAD_STARTED = 'lead.started';
    case LEAD_IDENTITY_CONFIRMATION_REQUESTED = 'lead.identity.confirmation.requested';
    case LEAD_IDENTITY_DECISION_RECORDED = 'lead.identity.decision.recorded';
    case LEAD_QUESTION_ASKED = 'lead.question.asked';
    case LEAD_ANSWER_RECORDED = 'lead.answer.recorded';
    case LEAD_REQUESTED = 'lead.requested';

    // Turn lifecycle telemetry (non-business events)
    case TURN_STARTED = 'turn.started';
    case TURN_COMPLETED = 'turn.completed';
    case TURN_FAILED = 'turn.failed';

    /**
     * Check if this event type produces a message projection.
     */
    public function producesMessage(): bool
    {
        return match ($this) {
            self::USER_MESSAGE_CREATED,
            self::ASSISTANT_MESSAGE_CREATED => true,
            default => false,
        };
    }

    /**
     * Get the message role for event types that produce messages.
     */
    public function messageRole(): ?string
    {
        return match ($this) {
            self::USER_MESSAGE_CREATED => 'user',
            self::ASSISTANT_MESSAGE_CREATED => 'assistant',
            default => null,
        };
    }
}
