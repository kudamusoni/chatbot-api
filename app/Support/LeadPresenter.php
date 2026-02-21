<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonInterface;

class LeadPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function listItem(Lead $lead, User $viewer): array
    {
        return [
            'id' => $lead->id,
            'conversation_id' => $lead->conversation_id,
            'name' => self::nameForViewer($lead->name, $viewer),
            'status' => $lead->status,
            'created_at' => self::formatUtc($lead->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Lead $lead, User $viewer): array
    {
        return [
            'id' => $lead->id,
            'status' => $lead->status,
            'name' => self::nameForViewer($lead->name, $viewer),
            'email' => self::emailForViewer((string) $lead->email, $viewer),
            'phone' => self::phoneForViewer((string) $lead->phone_normalized, $viewer),
            'notes' => $lead->notes,
            'created_at' => self::formatUtc($lead->created_at),
            'updated_at' => self::formatUtc($lead->updated_at),
            'meta' => [
                'conversation_id' => $lead->conversation_id,
                'valuation_id' => $lead->conversation()
                    ->first()?->valuations()
                    ->orderByDesc('created_at')
                    ->value('id'),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function exportRow(Lead $lead, User $viewer): array
    {
        return [
            $lead->id,
            self::nameForViewer($lead->name, $viewer),
            self::emailForViewer((string) $lead->email, $viewer),
            self::phoneForViewer((string) $lead->phone_normalized, $viewer),
            $lead->status,
            self::formatUtc($lead->created_at) ?? '',
        ];
    }

    private static function canViewFullPii(User $viewer): bool
    {
        // support_admin is always masked; tenant members and super_admin get full PII.
        return !$viewer->isSupportAdmin();
    }

    private static function nameForViewer(?string $name, User $viewer): ?string
    {
        if ($name === null || self::canViewFullPii($viewer)) {
            return $name;
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if ($parts === []) {
            return null;
        }

        $masked = array_map(function (string $part): string {
            $len = mb_strlen($part);
            if ($len <= 2) {
                return mb_substr($part, 0, 1) . '***';
            }

            return mb_substr($part, 0, 2) . '***';
        }, $parts);

        return implode(' ', $masked);
    }

    private static function emailForViewer(string $email, User $viewer): string
    {
        if (self::canViewFullPii($viewer)) {
            return $email;
        }

        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }

        [$local, $domain] = $parts;
        $domainParts = explode('.', $domain, 2);
        $domainName = $domainParts[0] ?? '';
        $domainTld = $domainParts[1] ?? '';

        $localPrefix = mb_substr($local, 0, min(2, mb_strlen($local)));
        $domainPrefix = mb_substr($domainName, 0, min(2, mb_strlen($domainName)));

        $masked = $localPrefix . '***@' . $domainPrefix . '***';
        if ($domainTld !== '') {
            $masked .= '.' . $domainTld;
        }

        return $masked;
    }

    private static function phoneForViewer(string $phone, User $viewer): string
    {
        if (self::canViewFullPii($viewer)) {
            return $phone;
        }

        $plus = str_starts_with($phone, '+') ? '+' : '';
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '***';
        }

        $prefix = substr($digits, 0, min(2, strlen($digits)));
        $suffix = substr($digits, -4);

        return $plus . $prefix . '******' . $suffix;
    }

    private static function formatUtc(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
