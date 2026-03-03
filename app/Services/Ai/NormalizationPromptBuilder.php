<?php

namespace App\Services\Ai;

use App\Models\Conversation;

class NormalizationPromptBuilder
{
    /**
     * @return array<int, array{role:string,content:string}>
     */
    public function build(Conversation $conversation, string $questionKey, string $rawValue): array
    {
        $system = [
            'role' => 'system',
            'content' => 'Normalize short appraisal descriptors. Return concise output only.',
        ];

        $user = [
            'role' => 'user',
            'content' => json_encode([
                'question_key' => $questionKey,
                'raw_value' => $rawValue,
                'conversation_state' => $conversation->state->value,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        return [$system, $user];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'normalized' => null,
            'confidence' => 0.0,
            'candidates' => [],
            'needs_clarification' => false,
            'clarifying_question' => null,
        ];
    }
}

