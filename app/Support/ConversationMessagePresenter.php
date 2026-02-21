<?php

namespace App\Support;

use App\Models\ConversationMessage;
use Carbon\CarbonInterface;

class ConversationMessagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(ConversationMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'created_at' => self::formatUtc($message->created_at),
        ];
    }

    private static function formatUtc(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
