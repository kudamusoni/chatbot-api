<?php

namespace App\Enums;

/**
 * Source type for product catalog items.
 *
 * - sold: Actual sale records (highest confidence)
 * - asking: Listed prices (moderate confidence)
 * - estimate: Appraisal estimates (lower confidence)
 */
enum ProductSource: string
{
    case SOLD = 'sold';
    case ASKING = 'asking';
    case ESTIMATE = 'estimate';

    /**
     * Get the weight for valuation confidence scoring.
     * Sold items are weighted higher than asking/estimates.
     */
    public function weight(): int
    {
        return match ($this) {
            self::SOLD => 3,
            self::ASKING => 2,
            self::ESTIMATE => 1,
        };
    }
}
