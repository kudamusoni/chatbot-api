<?php

namespace App\Enums;

enum ValuationStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    /**
     * Check if this status represents a terminal state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED => true,
            default => false,
        };
    }
}
