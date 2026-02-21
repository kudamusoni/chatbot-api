<?php

namespace App\Services;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class LeadPiiNormalizer
{
    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function emailHash(string $email): string
    {
        return hash('sha256', $this->normalizeEmail($email));
    }

    public function normalizePhone(string $phone, ?string $defaultRegion = null): string
    {
        $value = trim($phone);
        if ($value === '') {
            return '';
        }

        $region = $defaultRegion ?: (string) config('leads.default_phone_region', 'GB');

        if (class_exists(PhoneNumberUtil::class)) {
            $util = PhoneNumberUtil::getInstance();

            try {
                $parsed = $util->parse($value, strtoupper($region));

                if ($util->isValidNumber($parsed)) {
                    return $util->format($parsed, PhoneNumberFormat::E164);
                }
            } catch (NumberParseException) {
                // Fall through to permissive normalization for resilience.
            }
        }

        $hasPlus = str_starts_with($value, '+');
        $digitsOnly = preg_replace('/\D+/', '', $value) ?? '';

        return $hasPlus ? '+' . $digitsOnly : $digitsOnly;
    }

    public function phoneHash(string $phone, ?string $defaultRegion = null): string
    {
        return hash('sha256', $this->normalizePhone($phone, $defaultRegion));
    }
}
