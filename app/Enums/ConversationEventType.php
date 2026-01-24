<?php

namespace App\Enums;

enum ConversationEventType: string
{
    case USER_MESSAGE_CREATED = 'user.message.created';
    case ASSISTANT_MESSAGE_CREATED = 'assistant.message.created';
    case APPRAISAL_QUESTION_ASKED = 'appraisal.question.asked';
    case APPRAISAL_CONFIRMED = 'appraisal.confirmed';
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
