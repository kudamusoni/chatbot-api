<?php

namespace App\Support;

use App\Models\AppraisalQuestion;
use Carbon\CarbonInterface;

class AppraisalQuestionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(AppraisalQuestion $question): array
    {
        return [
            'id' => $question->id,
            'key' => $question->key,
            'question' => $question->label,
            'type' => $question->input_type,
            'options' => self::normalizeOptions($question->options),
            'is_required' => (bool) $question->required,
            'help_text' => $question->help_text,
            'order_index' => (int) $question->order_index,
            'is_active' => (bool) $question->is_active,
            'created_at' => self::formatUtc($question->created_at),
            'updated_at' => self::formatUtc($question->updated_at),
        ];
    }

    /**
     * @param mixed $options
     * @return array<int, string>|null
     */
    private static function normalizeOptions(mixed $options): ?array
    {
        if (!is_array($options)) {
            return null;
        }

        $normalized = [];
        foreach ($options as $key => $value) {
            if (is_string($value)) {
                $normalized[] = $value;
                continue;
            }

            if (is_string($key) && is_string($value)) {
                $normalized[] = $value;
            }
        }

        return $normalized === [] ? null : array_values($normalized);
    }

    private static function formatUtc(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
