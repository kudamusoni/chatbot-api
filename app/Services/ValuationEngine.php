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
        'old', 'used', 'year', 'years', 'condition', 'decent', 'good',
    ];
    private const ITEM_TYPE_MIN_TOKENS = 1;
    private const MIN_RELEVANT_COMPS = 3;

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
        [$rawSnapshot, $normalizedSnapshot, $normalizationMeta] = $this->extractSnapshotSections($inputSnapshot);
        $snapshotCurrency = $this->snapshotCurrency($rawSnapshot);
        if ($snapshotCurrency !== null) {
            $currency = $snapshotCurrency;
        }
        $keyValues = $this->searchKeyValues($rawSnapshot, $normalizedSnapshot, $normalizationMeta);
        $preferredTerms = $this->normalizedTerms($normalizedSnapshot, $normalizationMeta);
        $rawTerms = $this->extractSearchTerms($rawSnapshot);
        $keyTerms = [];
        foreach ($keyValues as $value) {
            $keyTerms = [...$keyTerms, ...$this->extractSearchTerms([$value])];
        }
        $terms = array_values(array_unique([...$preferredTerms, ...$keyTerms, ...$rawTerms]));
        $terms = array_slice($terms, 0, 12);

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

        $comps = $this->prioritizePreferredComps($comps, $normalizedSnapshot, $normalizationMeta);
        $comps = $this->scoreAndFilterComps($comps, $keyValues, $rawTerms);

        if ($comps->isEmpty()) {
            return $this->zeroMatchResult();
        }

        // 3. Bucket by source
        $signalsUsed = [
            'sold' => $comps->where('source', ProductSource::SOLD)->count(),
            'asking' => $comps->where('source', ProductSource::ASKING)->count(),
            'estimates' => $comps->where('source', ProductSource::ESTIMATE)->count(),
        ];

        // 4. Extract effective prices (in cents/pence).
        // Estimate comps use low/high midpoint when both values exist.
        $prices = $comps->map(fn (ProductCatalog $comp) => $this->effectivePrice($comp))
            ->sort()
            ->values()
            ->all();
        $count = count($prices);

        // 5. Compute median
        $median = $this->computeMedian($prices);

        // 6. Compute range (p25-p75 if count >= 5, else min-max)
        $range = $this->computeRange($prices);

        // 7. Calculate confidence using explicit rubric
        $confidence = $this->computeConfidence($count, $signalsUsed['sold']);
        if (
            isset($keyValues['item_type'])
            && is_string($keyValues['item_type'])
            && trim($keyValues['item_type']) !== ''
            && $count < self::MIN_RELEVANT_COMPS
        ) {
            // Deterministic low-sample guard for anchored lookups.
            $confidence = min($confidence, 1);
        }
        $cap = $this->resolveConfidenceCap($normalizationMeta);
        if ($cap !== null) {
            $confidence = min($confidence, $cap);
        }

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
     * @param array<string, mixed> $normalizationMeta
     */
    private function resolveConfidenceCap(array $normalizationMeta): ?float
    {
        $preflight = is_array($normalizationMeta['__preflight'] ?? null)
            ? $normalizationMeta['__preflight']
            : [];
        $explicitCap = $preflight['confidence_cap'] ?? null;
        if (is_numeric($explicitCap)) {
            return max(0.0, min(1.0, (float) $explicitCap));
        }

        $status = (string) ($preflight['status'] ?? '');
        if ($status === 'SKIPPED') {
            return (float) config('appraisal.confidence_caps.skipped', 0.5);
        }
        if ($status === 'AI_FAILED') {
            return (float) config('appraisal.confidence_caps.ai_failed', 0.4);
        }

        return null;
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
            'price' => $this->effectivePrice($comp),
            'source' => $comp->source->value,
            'low_estimate' => $comp->low_estimate,
            'high_estimate' => $comp->high_estimate,
        ])->values()->all();
    }

    private function effectivePrice(ProductCatalog $comp): int
    {
        if ($comp->source === ProductSource::ESTIMATE
            && $comp->low_estimate !== null
            && $comp->high_estimate !== null
        ) {
            return (int) round(((int) $comp->low_estimate + (int) $comp->high_estimate) / 2);
        }

        return (int) $comp->price;
    }

    /**
     * Extract search terms from the input snapshot.
     * Tokenizes values, removes stopwords, and deduplicates.
     */
    private function extractSearchTerms(array $snapshot): array
    {
        $terms = [];
        $values = $this->flattenSnapshotValues($snapshot);

        foreach ($values as $rawValue) {
            $value = strtolower(trim($rawValue));

            if ($value === '') {
                continue;
            }

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
     * @param array<string, mixed> $inputSnapshot
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function extractSnapshotSections(array $inputSnapshot): array
    {
        $raw = is_array($inputSnapshot['raw'] ?? null) ? $inputSnapshot['raw'] : $inputSnapshot;
        $normalized = is_array($inputSnapshot['normalized'] ?? null) ? $inputSnapshot['normalized'] : [];
        $meta = is_array($inputSnapshot['normalization_meta'] ?? null) ? $inputSnapshot['normalization_meta'] : [];

        return [$raw, $normalized, $meta];
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $meta
     * @return list<string>
     */
    private function normalizedTerms(array $normalized, array $meta): array
    {
        $threshold = (float) config('ai.normalization.confidence_threshold', 0.75);
        $terms = [];

        foreach ($normalized as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $confidence = (float) data_get($meta, "{$key}.confidence", 0);
            if ($confidence < $threshold) {
                continue;
            }

            $words = $this->extractSearchTerms([$value]);
            foreach ($words as $word) {
                $terms[] = $word;
            }
        }

        return array_values(array_unique($terms));
    }

    private function prioritizePreferredComps($comps, array $normalized, array $meta)
    {
        $priorityKeys = ['maker', 'item_type', 'model'];
        $priorityValues = [];
        $threshold = (float) config('ai.normalization.confidence_threshold', 0.75);

        foreach ($priorityKeys as $key) {
            $value = $normalized[$key] ?? null;
            $confidence = (float) data_get($meta, "{$key}.confidence", 0);
            if (is_string($value) && trim($value) !== '' && $confidence >= $threshold) {
                $priorityValues[] = mb_strtolower(trim($value));
            }
        }

        if ($priorityValues === []) {
            return $comps;
        }

        $scored = $comps->map(function (ProductCatalog $comp) use ($priorityValues) {
            $haystack = mb_strtolower((string) ($comp->normalized_text ?? ''));
            $score = 0;
            foreach ($priorityValues as $value) {
                if (str_contains($haystack, $value)) {
                    $score++;
                }
            }

            return ['comp' => $comp, 'score' => $score];
        });

        $maxScore = (int) $scored->max('score');
        if ($maxScore <= 0) {
            return $comps;
        }

        return $scored
            ->filter(fn (array $item) => $item['score'] > 0)
            ->sortByDesc('score')
            ->pluck('comp')
            ->values();
    }

    /**
     * @param array<string, string|null> $keyValues
     * @param list<string> $rawTerms
     */
    private function scoreAndFilterComps($comps, array $keyValues, array $rawTerms)
    {
        $itemType = mb_strtolower(trim((string) ($keyValues['item_type'] ?? '')));
        $maker = mb_strtolower(trim((string) ($keyValues['maker'] ?? '')));
        $model = mb_strtolower(trim((string) ($keyValues['model'] ?? '')));
        $itemTypeTokens = $itemType !== '' ? $this->extractSearchTerms([$itemType]) : [];
        $makerTokens = $maker !== '' ? $this->extractSearchTerms([$maker]) : [];
        $modelTokens = $model !== '' ? $this->extractSearchTerms([$model]) : [];
        $rawTerms = array_values(array_unique($rawTerms));

        $scored = $comps->map(function (ProductCatalog $comp) use (
            $itemType,
            $maker,
            $model,
            $itemTypeTokens,
            $makerTokens,
            $modelTokens,
            $rawTerms
        ) {
            $haystack = mb_strtolower((string) ($comp->normalized_text ?? ''));
            $score = 0;
            $makerMatched = false;
            $modelMatched = false;

            if ($itemType !== '') {
                $itemTypeMatched = false;
                if ($this->containsPhrase($haystack, $itemType)) {
                    $score += 8;
                    $itemTypeMatched = true;
                } else {
                    $tokenHits = 0;
                    foreach ($itemTypeTokens as $token) {
                        if ($this->containsWholeWord($haystack, $token)) {
                            $tokenHits++;
                        }
                    }
                    if ($tokenHits >= self::ITEM_TYPE_MIN_TOKENS) {
                        $score += 5;
                        $itemTypeMatched = true;
                    }
                }

                if (!$itemTypeMatched) {
                    return null;
                }
            }

            if ($maker !== '') {
                if ($this->containsPhrase($haystack, $maker)) {
                    $score += 4;
                    $makerMatched = true;
                } else {
                    foreach ($makerTokens as $token) {
                        if ($this->containsWholeWord($haystack, $token)) {
                            $score += 2;
                            $makerMatched = true;
                            break;
                        }
                    }
                }
            }

            if ($model !== '') {
                if ($this->containsPhrase($haystack, $model)) {
                    $score += 4;
                    $modelMatched = true;
                } else {
                    foreach ($modelTokens as $token) {
                        if ($this->containsWholeWord($haystack, $token)) {
                            $score += 2;
                            $modelMatched = true;
                            break;
                        }
                    }
                }
            }

            // If user gave maker/model, require at least one of them to match.
            if (($maker !== '' || $model !== '') && !$makerMatched && !$modelMatched) {
                return null;
            }

            $otherHits = 0;
            foreach ($rawTerms as $token) {
                if ($this->containsWholeWord($haystack, $token)) {
                    $otherHits++;
                }
            }
            $score += min(3, $otherHits);

            if ($score < 2) {
                return null;
            }

            return ['comp' => $comp, 'score' => $score];
        })->filter();

        return $scored
            ->sortByDesc('score')
            ->pluck('comp')
            ->values();
    }

    private function containsPhrase(string $haystack, string $phrase): bool
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return false;
        }

        $pattern = '/\b' . preg_quote($phrase, '/') . '\b/u';

        return preg_match($pattern, $haystack) === 1;
    }

    private function containsWholeWord(string $haystack, string $word): bool
    {
        $word = trim($word);
        if ($word === '') {
            return false;
        }

        $pattern = '/\b' . preg_quote($word, '/') . '\b/u';

        return preg_match($pattern, $haystack) === 1;
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $meta
     * @return array{maker:?string,model:?string,item_type:?string}
     */
    private function searchKeyValues(array $raw, array $normalized, array $meta): array
    {
        $keys = ['maker', 'model', 'item_type'];
        $result = ['maker' => null, 'model' => null, 'item_type' => null];
        $threshold = (float) config('appraisal.resolved_confidence_threshold', 0.75);

        foreach ($keys as $key) {
            $normalizedValue = $normalized[$key] ?? null;
            $confidence = (float) data_get($meta, "{$key}.confidence", 0);
            if (is_string($normalizedValue) && trim($normalizedValue) !== '' && $confidence >= $threshold) {
                $result[$key] = trim($normalizedValue);
                continue;
            }

            $rawValue = $raw[$key] ?? null;
            if (is_string($rawValue) && trim($rawValue) !== '') {
                $result[$key] = trim($rawValue);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $rawSnapshot
     */
    private function snapshotCurrency(array $rawSnapshot): ?string
    {
        $currency = $rawSnapshot['currency'] ?? null;
        if (!is_string($currency) || trim($currency) === '') {
            return null;
        }

        return strtoupper(trim($currency));
    }

    /**
     * Flatten snapshot values into searchable strings.
     *
     * This allows matching on any client-defined appraisal keys,
     * not only a predefined set of known fields.
     *
     * @return list<string>
     */
    private function flattenSnapshotValues(array $snapshot): array
    {
        $values = [];

        foreach ($snapshot as $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->flattenSnapshotValues($value));
                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value)) {
                $values[] = (string) $value;
            }
        }

        return $values;
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
