<?php

namespace App\Services;

use App\Enums\ProductSource;
use App\Models\ProductCatalog;

/**
 * Deterministic valuation engine that computes price estimates
 * from client-scoped product catalog data.
 *
 * No randomness, no ML, no GPT math - purely rule-based.
 */
class ValuationEngine
{
    /**
     * Maximum number of comps to consider.
     */
    private const MAX_COMPS = 200;

    /**
     * Number of sample comps to include for transparency.
     */
    private const SAMPLE_SIZE = 5;

    /**
     * Stopwords to remove from search terms.
     */
    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'is', 'it', 'as', 'be', 'was', 'were',
        'unknown', 'n/a', 'na', 'none', 'not', 'no',
    ];

    /**
     * Compute a valuation based on the input snapshot.
     *
     * @param string $clientId The client ID for tenant isolation
     * @param array $inputSnapshot The appraisal snapshot (maker, material, etc.)
     * @param string $currency The currency to filter comps by (default: GBP)
     * @return array The valuation result
     */
    public function compute(string $clientId, array $inputSnapshot, string $currency = 'GBP'): array
    {
        // 1. Extract and clean search terms from snapshot
        $terms = $this->extractSearchTerms($inputSnapshot);

        if (empty($terms)) {
            return $this->zeroMatchResult();
        }

        // 2. Query product catalog for matching comps
        $comps = ProductCatalog::forClient($clientId)
            ->withCurrency($currency)
            ->searchTerms($terms)
            ->limit(self::MAX_COMPS)
            ->get();

        if ($comps->isEmpty()) {
            return $this->zeroMatchResult();
        }

        // 3. Bucket by source
        $signalsUsed = [
            'sold' => $comps->where('source', ProductSource::SOLD)->count(),
            'asking' => $comps->where('source', ProductSource::ASKING)->count(),
            'estimates' => $comps->where('source', ProductSource::ESTIMATE)->count(),
        ];

        // 4. Extract prices (in cents/pence)
        $prices = $comps->pluck('price')->sort()->values()->all();
        $count = count($prices);

        // 5. Compute median
        $median = $this->computeMedian($prices);

        // 6. Compute range (p25-p75 if count >= 5, else min-max)
        $range = $this->computeRange($prices);

        // 7. Calculate confidence using explicit rubric
        $confidence = $this->computeConfidence($count, $signalsUsed['sold']);

        // 8. Build sample of matched comps for transparency/debugging
        $matchedCompsSample = $this->buildCompsSample($comps);

        return [
            'count' => $count,
            'range' => $range,
            'median' => $median,
            'confidence' => $confidence,
            'data_quality' => 'internal',
            'signals_used' => $signalsUsed,
            'matched_comps_sample' => $matchedCompsSample,
        ];
    }

    /**
     * Build a sample of matched comps for transparency.
     * Returns up to SAMPLE_SIZE comps, prioritizing sold items.
     */
    private function buildCompsSample($comps): array
    {
        // Prioritize sold items, then by price descending for variety
        $sorted = $comps->sortBy([
            ['source', 'asc'], // SOLD comes first alphabetically
            ['price', 'desc'],
        ]);

        return $sorted->take(self::SAMPLE_SIZE)->map(fn ($comp) => [
            'title' => $comp->title,
            'price' => $comp->price,
            'source' => $comp->source->value,
        ])->values()->all();
    }

    /**
     * Extract search terms from the input snapshot.
     * Tokenizes values, removes stopwords, and deduplicates.
     */
    private function extractSearchTerms(array $snapshot): array
    {
        $terms = [];

        // Fields to extract terms from (prioritized)
        $searchableFields = ['maker', 'material', 'title', 'description', 'type', 'style'];

        foreach ($searchableFields as $field) {
            if (!isset($snapshot[$field]) || $snapshot[$field] === '') {
                continue;
            }

            $value = strtolower(trim($snapshot[$field]));

            // Split into words
            $words = preg_split('/[\s,;.\-_]+/', $value, -1, PREG_SPLIT_NO_EMPTY);

            foreach ($words as $word) {
                // Skip stopwords and very short words
                if (strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                    continue;
                }

                $terms[] = $word;
            }
        }

        // Deduplicate and limit to top 5 terms
        return array_slice(array_unique($terms), 0, 5);
    }

    /**
     * Compute the median price from a sorted array of prices.
     */
    private function computeMedian(array $sortedPrices): int
    {
        $count = count($sortedPrices);

        if ($count === 0) {
            return 0;
        }

        $middle = (int) floor(($count - 1) / 2);

        if ($count % 2 === 1) {
            return $sortedPrices[$middle];
        }

        // Even count: average of two middle values
        return (int) round(($sortedPrices[$middle] + $sortedPrices[$middle + 1]) / 2);
    }

    /**
     * Compute the price range.
     * Uses p25-p75 if count >= 5, otherwise min-max.
     */
    private function computeRange(array $sortedPrices): array
    {
        $count = count($sortedPrices);

        if ($count === 0) {
            return ['low' => 0, 'high' => 0];
        }

        if ($count >= 5) {
            // Use 25th and 75th percentile for more stable range
            $p25Index = (int) floor($count * 0.25);
            $p75Index = (int) floor($count * 0.75);

            return [
                'low' => $sortedPrices[$p25Index],
                'high' => $sortedPrices[$p75Index],
            ];
        }

        // Small sample: use min-max
        return [
            'low' => $sortedPrices[0],
            'high' => $sortedPrices[$count - 1],
        ];
    }

    /**
     * Compute confidence score using explicit rubric.
     *
     * Rubric (0-3):
     * - base = 0
     * - +1 if count >= 5
     * - +1 if sold_count >= 1
     * - +1 if sold_count >= 3 OR count >= 15
     * - cap at 3
     */
    private function computeConfidence(int $count, int $soldCount): int
    {
        $confidence = 0;

        if ($count >= 5) {
            $confidence++;
        }

        if ($soldCount >= 1) {
            $confidence++;
        }

        if ($soldCount >= 3 || $count >= 15) {
            $confidence++;
        }

        return min($confidence, 3);
    }

    /**
     * Return the zero-match result contract.
     * Used when no comps are found.
     */
    private function zeroMatchResult(): array
    {
        return [
            'count' => 0,
            'range' => null,
            'median' => null,
            'confidence' => 0,
            'data_quality' => 'internal',
            'signals_used' => [
                'sold' => 0,
                'asking' => 0,
                'estimates' => 0,
            ],
            'matched_comps_sample' => [],
        ];
    }
}
