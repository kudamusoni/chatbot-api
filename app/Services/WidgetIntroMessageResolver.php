<?php

namespace App\Services;

use App\Models\ClientSetting;

class WidgetIntroMessageResolver
{
    public const DEFAULT_FALLBACK_MESSAGE = 'Thank you for the message. Do you have any more questions?';

    public function forFallback(string $clientId): string
    {
        $settings = ClientSetting::forClientOrCreate($clientId);
        $prompt = is_array($settings->prompt_settings) ? $settings->prompt_settings : [];
        $fallback = trim((string) ($prompt['fallback_message'] ?? ''));

        return $fallback !== '' ? $fallback : self::DEFAULT_FALLBACK_MESSAGE;
    }

    public function forClient(string $clientId): string
    {
        return $this->forFallback($clientId);
    }
}
