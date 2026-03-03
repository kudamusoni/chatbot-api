<?php

namespace App\Services;

use App\Models\AiRequest;
use App\Models\AppraisalQuestion;
use App\Models\ClientSetting;
use App\Models\Conversation;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\AiUsageLimiter;
use App\Services\Ai\NormalizationPromptBuilder;
use Illuminate\Support\Str;

class AppraisalPreflightService
{
    public function __construct(
        private readonly AiClient $aiClient,
        private readonly AiUsageLimiter $limiter,
        private readonly NormalizationPromptBuilder $promptBuilder
    ) {}

    /**
     * @param array<string, mixed> $rawSnapshot
     * @return array{
     *   passed: bool,
     *   snapshot: array{raw: array<string,mixed>, normalized: array<string,mixed>, normalization_meta: array<string,mixed>},
     *   missing_fields: list<string>,
     *   low_confidence_fields: list<array{key:string,confidence:float,candidates:list<string>,normalized:mixed}>,
     *   next_question_key: ?string,
     *   message: ?string,
     *   preflight_status: 'PASSED'|'FAILED'|'SKIPPED'|'AI_FAILED',
     *   preflight_details: array<string,mixed>,
     *   confidence_cap: ?float
     * }
     */
    public function run(string $clientId, string $conversationId, array $rawSnapshot): array
    {
        $conversation = Conversation::query()
            ->where('id', $conversationId)
            ->where('client_id', $clientId)
            ->first();

        if (!$conversation) {
            return $this->failedResult(
                raw: $rawSnapshot,
                message: 'Before I can value it, I need more appraisal details.',
                nextQuestionKey: null,
                preflightStatus: 'FAILED',
                preflightDetails: ['error' => 'CONVERSATION_NOT_FOUND']
            );
        }

        $requiredQuestions = AppraisalQuestion::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->where('required', true)
            ->orderBy('order_index')
            ->get(['key']);

        $requiredKeys = $requiredQuestions->pluck('key')->values()->all();
        $strictKeys = config('appraisal.strict_keys', ['maker', 'item_type', 'model']);
        if (!is_array($strictKeys)) {
            $strictKeys = ['maker', 'item_type', 'model'];
        }
        $strictRequiredOrder = $requiredQuestions
            ->pluck('key')
            ->filter(fn ($key) => in_array($key, $strictKeys, true))
            ->values()
            ->all();
        $parse = $this->deterministicParse($rawSnapshot);

        $preflightDetails = [
            'schema' => 'preflight:v1',
            'ai_model' => (string) config('ai.models.normalize'),
            'ai_provider' => (string) config('ai.provider', 'openai'),
            'parse' => $parse,
        ];

        $normalized = [];
        $meta = [];
        $status = 'PASSED';
        $confidenceCap = null;

        $settings = ClientSetting::forClientOrCreate($clientId);
        if (!config('ai.enabled') || !$settings->ai_enabled || !$settings->ai_normalization_enabled) {
            $status = 'SKIPPED';
            $confidenceCap = (float) config('appraisal.confidence_caps.skipped', 0.5);
            $preflightDetails['skip_reason'] = 'AI_DISABLED_BY_CLIENT_FLAG';
        } else {
            $limiterDecision = $this->limiter->allow($clientId, null);
            if (!$limiterDecision['allowed']) {
                $status = 'SKIPPED';
                $confidenceCap = (float) config('appraisal.confidence_caps.skipped', 0.5);
                $preflightDetails['skip_reason'] = match ((string) $limiterDecision['reason']) {
                    'CIRCUIT_OPEN' => 'AI_CIRCUIT_OPEN',
                    'RATE_LIMITED' => 'AI_RATE_LIMITED',
                    default => 'AI_RATE_LIMITED',
                };
            } else {
                $result = $this->normalizeSnapshot($conversation, $clientId, $rawSnapshot, $requiredKeys);
                $normalized = $result['normalized'];
                $meta = $result['meta'];
                if ($result['status'] === 'AI_FAILED') {
                    $status = 'AI_FAILED';
                    $confidenceCap = (float) config('appraisal.confidence_caps.ai_failed', 0.4);
                    $preflightDetails['ai_error_code'] = $result['error_code'];
                    $preflightDetails['ai_error_message'] = $result['error_message'];
                    if ($result['error_code'] === 'AI_BAD_JSON') {
                        $preflightDetails['telemetry'] = 'normalize_failed_schema';
                    } else {
                        $preflightDetails['telemetry'] = 'normalize_failed_provider';
                    }
                }
            }
        }

        $missingFields = [];
        $lowConfidenceFields = [];
        $nextQuestionKey = null;
        $threshold = (float) config('appraisal.resolved_confidence_threshold', 0.75);

        foreach ($requiredKeys as $requiredKey) {
            $rawValue = trim((string) ($rawSnapshot[$requiredKey] ?? ''));
            $isMissing = $rawValue === '';
            $isSkipped = $this->isSkipResponse($rawValue);
            $metaForKey = is_array($meta[$requiredKey] ?? null) ? $meta[$requiredKey] : [];
            $confidence = array_key_exists('confidence', $metaForKey)
                ? (float) $metaForKey['confidence']
                : null;

            if ($isMissing) {
                $missingFields[] = $requiredKey;
            }

            if (in_array($requiredKey, $strictKeys, true)) {
                if ($isMissing || $isSkipped) {
                    if ($nextQuestionKey === null) {
                        $nextQuestionKey = $requiredKey;
                    }
                    continue;
                }

                if ($confidence !== null && $confidence < $threshold) {
                    $lowConfidenceFields[] = [
                        'key' => $requiredKey,
                        'confidence' => $confidence,
                        'candidates' => is_array($metaForKey['candidates'] ?? null) ? $metaForKey['candidates'] : [],
                        'normalized' => $normalized[$requiredKey] ?? null,
                    ];
                    if ($nextQuestionKey === null) {
                        $nextQuestionKey = $requiredKey;
                    }
                }
            } else {
                if ($isMissing || $isSkipped || ($confidence !== null && $confidence < $threshold)) {
                    $confidenceCap = $confidenceCap ?? (float) config('appraisal.confidence_caps.non_strict_unresolved', 0.7);
                }
            }
        }

        if ($nextQuestionKey === null && $strictRequiredOrder !== []) {
            foreach ($strictRequiredOrder as $strictKey) {
                $rawValue = trim((string) ($rawSnapshot[$strictKey] ?? ''));
                if ($rawValue === '') {
                    $nextQuestionKey = $strictKey;
                    break;
                }
            }
        }

        $failed = $nextQuestionKey !== null || $lowConfidenceFields !== [];
        if ($failed) {
            $status = 'FAILED';
            $nextQuestionKey = $nextQuestionKey ?? $lowConfidenceFields[0]['key'] ?? null;
            $message = $this->missingFieldMessage($nextQuestionKey);

            return [
                'passed' => false,
                'snapshot' => [
                    'raw' => $rawSnapshot,
                    'normalized' => $normalized,
                    'normalization_meta' => $meta,
                ],
                'missing_fields' => $missingFields,
                'low_confidence_fields' => $lowConfidenceFields,
                'next_question_key' => $nextQuestionKey,
                'message' => $message,
                'preflight_status' => $status,
                'preflight_details' => $preflightDetails,
                'confidence_cap' => $confidenceCap,
            ];
        }

        return [
            'passed' => true,
            'snapshot' => [
                'raw' => $rawSnapshot,
                'normalized' => $normalized,
                'normalization_meta' => $meta,
            ],
            'missing_fields' => $missingFields,
            'low_confidence_fields' => $lowConfidenceFields,
            'next_question_key' => null,
            'message' => null,
            'preflight_status' => $status,
            'preflight_details' => $preflightDetails,
            'confidence_cap' => $confidenceCap,
        ];
    }

    /**
     * @param array<string, mixed> $rawSnapshot
     * @param list<string> $requiredKeys
     * @return array{
     *   status: 'PASSED'|'AI_FAILED',
     *   normalized: array<string,mixed>,
     *   meta: array<string,mixed>,
     *   error_code: ?string,
     *   error_message: ?string
     * }
     */
    private function normalizeSnapshot(
        Conversation $conversation,
        string $clientId,
        array $rawSnapshot,
        array $requiredKeys
    ): array {
        $normalizeKeys = config('ai.normalization.keys', []);
        if (!is_array($normalizeKeys)) {
            $normalizeKeys = [];
        }

        $normalized = [];
        $meta = [];

        foreach ($requiredKeys as $questionKey) {
            if (!in_array($questionKey, $normalizeKeys, true)) {
                continue;
            }

            $rawValue = trim((string) ($rawSnapshot[$questionKey] ?? ''));
            if ($rawValue === '') {
                continue;
            }

            if ($this->isSkipResponse($rawValue)) {
                $normalized[$questionKey] = null;
                $meta[$questionKey] = [
                    'confidence' => 0.0,
                    'candidates' => [],
                    'needs_clarification' => false,
                    'clarifying_question' => null,
                    'user_skipped' => true,
                    'unresolved' => false,
                ];
                continue;
            }

            $messages = $this->promptBuilder->build($conversation, $questionKey, $rawValue);
            $schema = $this->promptBuilder->schema();

            $aiRequest = AiRequest::query()->create([
                'client_id' => $clientId,
                'conversation_id' => $conversation->id,
                'event_id' => null,
                'purpose' => 'NORMALIZE',
                'provider' => (string) config('ai.provider', 'openai'),
                'model' => (string) config('ai.models.normalize'),
                'prompt_version' => (string) config('ai.prompt_versions.normalize'),
                'policy_version' => config('ai.policy_version'),
                'prompt_hash' => hash('sha256', (string) json_encode($messages)),
                'status' => 'PENDING',
            ]);

            try {
                $jsonResult = $this->aiClient->json($messages, $schema, [
                    'model' => config('ai.models.normalize'),
                    'temperature' => 0.0,
                    'max_output_tokens' => config('ai.max_output_tokens'),
                ]);

                $normalized[$questionKey] = $jsonResult->data['normalized'] ?? null;
                $meta[$questionKey] = [
                    'confidence' => (float) ($jsonResult->data['confidence'] ?? 0),
                    'candidates' => is_array($jsonResult->data['candidates'] ?? null) ? $jsonResult->data['candidates'] : [],
                    'needs_clarification' => (bool) ($jsonResult->data['needs_clarification'] ?? false),
                    'clarifying_question' => $jsonResult->data['clarifying_question'] ?? null,
                    'user_skipped' => false,
                    'unresolved' => false,
                ];

                $aiRequest->update([
                    'status' => 'COMPLETED',
                    'input_tokens' => $jsonResult->inputTokens,
                    'output_tokens' => $jsonResult->outputTokens,
                    'cost_estimate_minor' => $jsonResult->costEstimateMinor,
                    'completed_at' => now(),
                ]);
                $this->limiter->recordSuccess($clientId);
            } catch (AiException $e) {
                $aiRequest->update([
                    'status' => 'FAILED',
                    'error_code' => $e->errorCode,
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                ]);
                $this->limiter->recordFailure($clientId, $e->errorCode);

                return [
                    'status' => 'AI_FAILED',
                    'normalized' => [],
                    'meta' => [],
                    'error_code' => $e->errorCode,
                    'error_message' => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $aiRequest->update([
                    'status' => 'FAILED',
                    'error_code' => 'AI_PROVIDER_ERROR',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                ]);
                $this->limiter->recordFailure($clientId, 'AI_PROVIDER_ERROR');

                return [
                    'status' => 'AI_FAILED',
                    'normalized' => [],
                    'meta' => [],
                    'error_code' => 'AI_PROVIDER_ERROR',
                    'error_message' => $e->getMessage(),
                ];
            }
        }

        return [
            'status' => 'PASSED',
            'normalized' => $normalized,
            'meta' => $meta,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{year: int|null, size: string|null, currency: string|null}
     */
    private function deterministicParse(array $raw): array
    {
        $text = implode(' ', array_map(
            fn ($v) => is_scalar($v) ? (string) $v : '',
            array_values($raw)
        ));
        $text = trim($text);

        $year = null;
        if (preg_match('/\b(18\d{2}|19\d{2}|20\d{2})\b/', $text, $m) === 1) {
            $year = (int) $m[1];
        } elseif (preg_match('/\b(18|19|20)\d0s\b/i', $text, $m) === 1) {
            $year = (int) "{$m[1]}00";
        }

        $size = null;
        if (preg_match('/\b\d+(\.\d+)?\s?(cm|mm|in|inch|inches)\b/i', $text, $m) === 1) {
            $size = trim($m[0]);
        }

        $currency = null;
        if (preg_match('/\bGBP|USD|EUR\b/i', $text, $m) === 1) {
            $currency = strtoupper($m[0]);
        } elseif (str_contains($text, '£')) {
            $currency = 'GBP';
        } elseif (str_contains($text, '$')) {
            $currency = 'USD';
        } elseif (str_contains($text, '€')) {
            $currency = 'EUR';
        }

        return [
            'year' => $year,
            'size' => $size,
            'currency' => $currency,
        ];
    }

    private function missingFieldMessage(?string $questionKey): string
    {
        $messages = config('appraisal.missing_prompts', []);
        if (is_array($messages) && is_string($questionKey) && isset($messages[$questionKey]) && is_string($messages[$questionKey])) {
            return $messages[$questionKey];
        }

        return 'Before I can value it, I need one more required detail.';
    }

    private function isSkipResponse(string $value): bool
    {
        $tokens = config('appraisal.skip_tokens', ['unknown', 'not sure', 'skip']);
        if (!is_array($tokens)) {
            $tokens = ['unknown', 'not sure', 'skip'];
        }
        $normalizedTokens = array_map(
            static fn ($token) => Str::lower(trim((string) $token)),
            $tokens
        );

        return in_array(Str::lower(trim($value)), $normalizedTokens, true);
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $preflightDetails
     * @return array{
     *   passed: bool,
     *   snapshot: array{raw: array<string,mixed>, normalized: array<string,mixed>, normalization_meta: array<string,mixed>},
     *   missing_fields: list<string>,
     *   low_confidence_fields: list<array{key:string,confidence:float,candidates:list<string>,normalized:mixed}>,
     *   next_question_key: ?string,
     *   message: ?string,
     *   preflight_status: 'FAILED',
     *   preflight_details: array<string,mixed>,
     *   confidence_cap: ?float
     * }
     */
    private function failedResult(
        array $raw,
        ?string $message,
        ?string $nextQuestionKey,
        string $preflightStatus,
        array $preflightDetails
    ): array {
        return [
            'passed' => false,
            'snapshot' => [
                'raw' => $raw,
                'normalized' => [],
                'normalization_meta' => [],
            ],
            'missing_fields' => [],
            'low_confidence_fields' => [],
            'next_question_key' => $nextQuestionKey,
            'message' => $message,
            'preflight_status' => $preflightStatus,
            'preflight_details' => $preflightDetails,
            'confidence_cap' => null,
        ];
    }
}
