<?php

namespace App\Enums;

enum ConversationState: string
{
    case CHAT = 'CHAT';
    case APPRAISAL_INTAKE = 'APPRAISAL_INTAKE';
    case APPRAISAL_CONFIRM = 'APPRAISAL_CONFIRM';
    case VALUATION_RUNNING = 'VALUATION_RUNNING';
    case VALUATION_READY = 'VALUATION_READY';
    case VALUATION_FAILED = 'VALUATION_FAILED';

    /**
     * Check if this state allows valuation retry.
     */
    public function canRetryValuation(): bool
    {
        return $this === self::VALUATION_FAILED;
    }
}
