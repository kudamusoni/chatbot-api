<?php

namespace App\Services;

class PriceParser
{
    /**
     * Parse major-unit price text into integer minor units.
     *
     * Rules (v1):
     * - strip currency symbols and spaces
     * - allow comma thousands separators
     * - reject comma decimals
     * - reject negatives and blanks
     * - reject values with > 2 decimal places
     */
    public function parseToMinorUnits(string $input): ?int
    {
        $value = trim($input);
        if ($value === '') {
            return null;
        }

        // Strip currency symbols and spaces but keep digits, dot, minus and comma for validation.
        $value = preg_replace('/[^0-9.,\-]/', '', $value) ?? '';
        $value = str_replace(' ', '', $value);

        if ($value === '') {
            return null;
        }

        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma) {
            // Comma-decimal formats are not supported in v1 (e.g. 95,00).
            // Only allow commas as thousands separators.
            $validThousands = $hasDot
                ? preg_match('/^\d{1,3}(,\d{3})+\.\d+$/', $value) === 1
                : preg_match('/^\d{1,3}(,\d{3})+$/', $value) === 1;

            if (!$validThousands) {
                return null;
            }

            $value = str_replace(',', '', $value);
        }

        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        if (str_starts_with($value, '-')) {
            return null;
        }

        $parts = explode('.', $value, 2);
        if (isset($parts[1]) && strlen($parts[1]) > 2) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }
}
