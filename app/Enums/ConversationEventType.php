<?php

namespace App\Enums;

/**
 * Event types for conversation events.
 *
 * State transitions:
 * - CHAT: user.message.created, assistant.message.created
 * - APPRAISAL_INTAKE: appraisal.question.asked (enters intake flow)
 * - APPRAISAL_CONFIRM: appraisal.confirmation.requested (show confirmation panel)
 * - VALUATION_RUNNING: appraisal.confirmed (user confirms), valuation.requested
 * - VALUATION_READY: valuation.completed
 */
enum ConversationEventType: string
{
    case USER_MESSAGE_CREATED = 'user.message.created';
    case ASSISTANT_MESSAGE_CREATED = 'assistant.message.created';

    // Appraisal flow
    case APPRAISAL_QUESTION_ASKED = 'appraisal.question.asked';
    case APPRAISAL_CONFIRMATION_REQUESTED = 'appraisal.confirmation.requested';
    case APPRAISAL_CONFIRMED = 'appraisal.confirmed';

    // Valuation flow
    case VALUATION_REQUESTED = 'valuation.requested';
    case VALUATION_COMPLETED = 'valuation.completed';

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
