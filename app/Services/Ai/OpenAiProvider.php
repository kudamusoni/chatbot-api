<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements AiProvider
{
    public function chat(array $messages, array $options = []): AiResult
    {
        $response = $this->request($messages, $options);
        $content = (string) data_get($response, 'choices.0.message.content', '');

        return new AiResult(
            content: $content,
            inputTokens: data_get($response, 'usage.prompt_tokens'),
            outputTokens: data_get($response, 'usage.completion_tokens'),
            costEstimateMinor: null
        );
    }

    public function json(array $messages, array $schema, array $options = []): AiJsonResult
    {
        $schemaInstructions = "Return only valid JSON object with keys: "
            . implode(', ', array_keys($schema)) . '.';

        $enrichedMessages = [
            ...$messages,
            ['role' => 'system', 'content' => $schemaInstructions],
        ];

        $response = $this->request($enrichedMessages, $options);
        $content = (string) data_get($response, 'choices.0.message.content', '');

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new AiException('AI_BAD_JSON', 'Provider returned invalid JSON payload.');
        }

        return new AiJsonResult(
            data: $decoded,
            inputTokens: data_get($response, 'usage.prompt_tokens'),
            outputTokens: data_get($response, 'usage.completion_tokens'),
            costEstimateMinor: null
        );
    }

    /**
     * @param array<int, array{role:string,content:string}> $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function request(array $messages, array $options): array
    {
        $baseUrl = rtrim((string) config('ai.openai.base_url'), '/');
        $apiKey = (string) config('ai.openai.api_key');
        $timeout = (int) config('ai.timeout_seconds', 12);

        if ($apiKey === '') {
            throw new AiException('AI_PROVIDER_ERROR', 'Missing OpenAI API key.');
        }

        $payload = [
            'model' => $options['model'] ?? config('ai.models.chat'),
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? config('ai.temperature', 0.2),
            'max_tokens' => $options['max_output_tokens'] ?? config('ai.max_output_tokens', 300),
        ];

        $response = Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('/chat/completions', $payload);

        if (!$response->successful()) {
            throw new AiException('AI_PROVIDER_ERROR', 'AI provider call failed with status ' . $response->status() . '.');
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new AiException('AI_PROVIDER_ERROR', 'AI provider returned non-JSON response.');
        }

        return $json;
    }
}

