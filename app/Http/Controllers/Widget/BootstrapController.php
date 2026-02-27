<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\BootstrapRequest;
use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;

class BootstrapController extends Controller
{
    /**
     * Create or resume a conversation.
     *
     * POST /api/widget/bootstrap
     */
    public function store(BootstrapRequest $request): JsonResponse
    {
        $clientId = $request->validated('client_id');
        $sessionToken = $request->validated('session_token');
        $client = Client::findOrFail($clientId);
        $settings = ClientSetting::forClientOrCreate((string) $client->id);
        $securityVersion = (int) ($settings->widget_security_version ?? 1);
        $promptSettings = $this->sanitizePromptSettings(is_array($settings->prompt_settings) ? $settings->prompt_settings : []);

        $conversation = null;
        $rawToken = null;

        // Try to resume existing conversation if token provided
        if ($sessionToken) {
            $conversation = Conversation::findByTokenForClient($sessionToken, $clientId);
            if ($conversation) {
                $rawToken = $sessionToken; // Keep the existing token
            }
        }

        // Create new conversation if no valid token or no token provided
        if (!$conversation) {
            [$conversation, $rawToken] = Conversation::createWithToken($clientId);
        }

        return response()->json([
            'session_token' => $rawToken,
            'conversation_id' => $conversation->id,
            'last_event_id' => $conversation->last_event_id ?? 0,
            'last_activity_at' => $conversation->last_activity_at?->toIso8601String(),
            'widget_security_version' => $securityVersion,
            'widget' => [
                'client_name' => (string) $client->name,
                'bot_name' => $settings->bot_name,
                'brand_color' => $settings->brand_color,
                'accent_color' => $settings->accent_color,
                'logo_url' => $settings->logo_url,
                'prompt_settings' => $promptSettings,
                'preset_questions' => $this->normalizePresetQuestions($promptSettings['preset_questions'] ?? []),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $promptSettings
     * @return array<string, mixed>
     */
    private function sanitizePromptSettings(array $promptSettings): array
    {
        unset($promptSettings['intro_message']);

        if (array_key_exists('preset_questions', $promptSettings)) {
            $promptSettings['preset_questions'] = $this->normalizePresetQuestions($promptSettings['preset_questions']);
        }

        return $promptSettings;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizePresetQuestions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            $normalized[] = $text;
        }

        return array_values(array_unique($normalized));
    }
}
