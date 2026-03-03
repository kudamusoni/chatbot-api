<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;

class AiClient
{
    public function __construct(
        private readonly AiProvider $provider
    ) {}

    /**
     * @param array<int, array{role:string,content:string}> $messages
     * @param array<string, mixed> $options
     */
    public function chat(array $messages, array $options = []): AiResult
    {
        $attempts = 0;
        $maxAttempts = 2;
        $last = null;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $result = $this->provider->chat($messages, $options);
                $content = $this->sanitizeText($result->content);

                if ($content === '') {
                    $content = (string) config('ai.fallback.assistant_message');
                }

                return new AiResult(
                    content: $content,
                    inputTokens: $result->inputTokens,
                    outputTokens: $result->outputTokens,
                    costEstimateMinor: $result->costEstimateMinor
                );
            } catch (AiException $e) {
                $last = $e;
            } catch (\Throwable $e) {
                $last = new AiException('AI_PROVIDER_ERROR', $e->getMessage());
            }
        }

        throw $last ?? new AiException('AI_PROVIDER_ERROR', 'Unknown provider failure.');
    }

    /**
     * @param array<int, array{role:string,content:string}> $messages
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $options
     */
    public function json(array $messages, array $schema, array $options = []): AiJsonResult
    {
        try {
            $result = $this->provider->json($messages, $schema, $options);
        } catch (AiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AiException('AI_PROVIDER_ERROR', $e->getMessage());
        }

        foreach (array_keys($schema) as $requiredKey) {
            if (!array_key_exists($requiredKey, $result->data)) {
                throw new AiException('AI_BAD_JSON', "Missing required key: {$requiredKey}");
            }
        }

        return $result;
    }

    private function sanitizeText(string $content): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content) ?? '';
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';
        $clean = trim($clean);
        $limit = (int) config('ai.assistant_max_chars', 1200);

        $clean = mb_strlen($clean) <= $limit
            ? $clean
            : trim(mb_substr($clean, 0, $limit));

        if ($this->violatesOutputPolicy($clean)) {
            return (string) config('ai.fallback.assistant_message');
        }

        return $clean;
    }

    private function violatesOutputPolicy(string $content): bool
    {
        $patterns = config('ai.policy.block_patterns', []);
        if (!is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            if (@preg_match($pattern, $content) === 1) {
                return true;
            }
        }

        return false;
    }
}
