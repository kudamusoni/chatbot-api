<?php

namespace App\Support;

use App\Models\Valuation;
use Carbon\CarbonInterface;

class ValuationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function listItem(Valuation $valuation): array
    {
        $result = is_array($valuation->result) ? $valuation->result : [];
        $range = is_array($result['range'] ?? null) ? $result['range'] : [];

        return [
            'id' => $valuation->id,
            'status' => $valuation->status->value,
            'confidence' => self::normalizeConfidence($result['confidence'] ?? 0),
            'median' => self::toIntOrNull($result['median'] ?? null),
            'range_low' => self::toIntOrNull($range['low'] ?? null),
            'range_high' => self::toIntOrNull($range['high'] ?? null),
            'count_comps' => (int) ($result['count'] ?? 0),
            'currency' => self::currency($valuation),
            'created_at' => self::formatUtc($valuation->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Valuation $valuation): array
    {
        $result = is_array($valuation->result) ? $valuation->result : [];
        $range = is_array($result['range'] ?? null) ? $result['range'] : [];

        return [
            'id' => $valuation->id,
            'status' => $valuation->status->value,
            'confidence' => self::normalizeConfidence($result['confidence'] ?? 0),
            'median' => self::toIntOrNull($result['median'] ?? null),
            'range_low' => self::toIntOrNull($range['low'] ?? null),
            'range_high' => self::toIntOrNull($range['high'] ?? null),
            'count_comps' => (int) ($result['count'] ?? 0),
            'signals_used' => is_array($result['signals_used'] ?? null) ? $result['signals_used'] : [],
            'input_snapshot' => is_array($valuation->input_snapshot) ? $valuation->input_snapshot : [],
            'result' => $result,
            'currency' => self::currency($valuation),
            'created_at' => self::formatUtc($valuation->created_at),
            'completed_at' => $valuation->status->value === 'COMPLETED' ? self::formatUtc($valuation->updated_at) : null,
        ];
    }

    private static function normalizeConfidence(mixed $raw): float
    {
        $value = is_numeric($raw) ? (float) $raw : 0.0;

        if ($value > 1.0) {
            $value = $value / 3.0;
        }

        if ($value < 0) {
            $value = 0.0;
        }

        if ($value > 1) {
            $value = 1.0;
        }

        return round($value, 4);
    }

    private static function currency(Valuation $valuation): string
    {
        $snapshot = is_array($valuation->input_snapshot) ? $valuation->input_snapshot : [];
        $currency = $snapshot['currency'] ?? null;

        if (!is_string($currency) || trim($currency) === '') {
            return 'GBP';
        }

        return strtoupper(trim($currency));
    }

    private static function toIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function formatUtc(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
