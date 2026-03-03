<?php

namespace App\Services\Ai;

use App\Models\ClientSetting;
use App\Models\Conversation;
use App\Models\ConversationMessage;

class ChatPromptBuilder
{
    /**
     * @return array<int, array{role:string,content:string}>
     */
    public function build(Conversation $conversation): array
    {
        $settings = ClientSetting::forClientOrCreate((string) $conversation->client_id);
        $botName = trim((string) ($settings->bot_name ?? 'Assistant'));

        $system = [
            'role' => 'system',
            'content' => "{$botName} is a concise assistant for valuation and appraisal support only. "
                . "Allowed topics: item appraisal intake, valuation context, expert review requests, and company contact/help related to this service. "
                . "If the user asks about unrelated topics (music, politics, general trivia, coding help, or other off-topic chat), politely refuse and redirect to appraisal/valuation help. "
                . "Do not hallucinate prices, comps, sources, or facts. Do not claim internet access or external verification. "
                . "Keep responses short and practical. If required details are missing, ask one focused follow-up question.",
        ];

        $history = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('event_id')
            ->limit(20)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn (ConversationMessage $m) => [
                'role' => $m->role === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $m->content,
            ])
            ->all();

        return [$system, ...$history];
    }
}
