<?php

namespace App\Jobs;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Models\AiRequest;
use App\Models\ClientSetting;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\AiUsageLimiter;
use App\Services\Ai\NormalizationPromptBuilder;
use App\Services\ConversationEventRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NormalizeAppraisalAnswerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly string $clientId,
        public readonly string $conversationId,
        public readonly int $requestEventId,
        public readonly string $turnId,
        public readonly string $questionKey,
        public readonly string $rawValue
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        AiClient $aiClient,
        AiUsageLimiter $limiter,
        NormalizationPromptBuilder $promptBuilder,
        ConversationEventRecorder $eventRecorder
    ): void {
        $conversation = Conversation::query()->where('id', $this->conversationId)->first();
        if (!$conversation || (string) $conversation->client_id !== $this->clientId) {
            Log::warning('NormalizeAppraisalAnswerJob tenant mismatch', [
                'client_id' => $this->clientId,
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        if (!in_array($conversation->state, [ConversationState::APPRAISAL_INTAKE, ConversationState::APPRAISAL_CONFIRM], true)) {
            return;
        }

        $requestEvent = ConversationEvent::query()
            ->where('id', $this->requestEventId)
            ->where('conversation_id', $conversation->id)
            ->first();

        if (!$requestEvent) {
            return;
        }

        $settings = ClientSetting::forClientOrCreate($this->clientId);
        if (!config('ai.enabled') || !$settings->ai_enabled || !$settings->ai_normalization_enabled) {
            return;
        }

        $limiterDecision = $limiter->allow($this->clientId, null);
        if (!$limiterDecision['allowed']) {
            $eventRecorder->record(
                $conversation,
                ConversationEventType::APPRAISAL_ANSWER_NORMALIZATION_FAILED,
                [
                    'turn_id' => $this->turnId,
                    'request_event_id' => $this->requestEventId,
                    'question_key' => $this->questionKey,
                    'error_code' => $this->mapLimiterReason((string) $limiterDecision['reason']),
                ],
                idempotencyKey: "appraisal.normalization.failed:{$this->requestEventId}",
                correlationId: $requestEvent->correlation_id
            );

            return;
        }

        $messages = $promptBuilder->build($conversation, $this->questionKey, $this->rawValue);
        $schema = $promptBuilder->schema();

        $aiRequest = AiRequest::query()->firstOrCreate(
            [
                'client_id' => $this->clientId,
                'conversation_id' => $this->conversationId,
                'event_id' => $this->requestEventId,
                'purpose' => 'NORMALIZE',
            ],
            [
                'provider' => (string) config('ai.provider', 'openai'),
                'model' => (string) config('ai.models.normalize'),
                'prompt_version' => (string) config('ai.prompt_versions.normalize'),
                'policy_version' => config('ai.policy_version'),
                'prompt_hash' => hash('sha256', json_encode($messages)),
                'status' => 'PENDING',
            ]
        );

        try {
            $result = $aiClient->json($messages, $schema, [
                'model' => config('ai.models.normalize'),
                'temperature' => 0.0,
                'max_output_tokens' => config('ai.max_output_tokens'),
            ]);

            $eventRecorder->record(
                $conversation,
                ConversationEventType::APPRAISAL_ANSWER_NORMALIZATION_COMPLETED,
                [
                    'turn_id' => $this->turnId,
                    'request_event_id' => $this->requestEventId,
                    'question_key' => $this->questionKey,
                    'raw_value' => $this->rawValue,
                    'normalized' => $result->data['normalized'],
                    'confidence' => (float) $result->data['confidence'],
                    'candidates' => is_array($result->data['candidates']) ? $result->data['candidates'] : [],
                    'needs_clarification' => (bool) $result->data['needs_clarification'],
                    'clarifying_question' => $result->data['clarifying_question'],
                    'ai_request_id' => $aiRequest->id,
                ],
                idempotencyKey: "appraisal.normalization.completed:{$this->requestEventId}",
                correlationId: $requestEvent->correlation_id
            );

            $aiRequest->update([
                'status' => 'COMPLETED',
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'cost_estimate_minor' => $result->costEstimateMinor,
                'completed_at' => now(),
            ]);
            $limiter->recordSuccess($this->clientId);
        } catch (AiException $e) {
            $aiRequest->update([
                'status' => 'FAILED',
                'error_code' => $e->errorCode,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            $limiter->recordFailure($this->clientId, $e->errorCode);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $conversation = Conversation::query()->where('id', $this->conversationId)->first();
        if (!$conversation || (string) $conversation->client_id !== $this->clientId) {
            return;
        }

        $requestEvent = ConversationEvent::query()
            ->where('id', $this->requestEventId)
            ->where('conversation_id', $conversation->id)
            ->first();

        $correlationId = $requestEvent?->correlation_id;
        $errorCode = $exception instanceof AiException ? $exception->errorCode : 'AI_PROVIDER_ERROR';

        /** @var ConversationEventRecorder $eventRecorder */
        $eventRecorder = app(ConversationEventRecorder::class);
        $eventRecorder->record(
            $conversation,
            ConversationEventType::APPRAISAL_ANSWER_NORMALIZATION_FAILED,
            [
                'turn_id' => $this->turnId,
                'request_event_id' => $this->requestEventId,
                'question_key' => $this->questionKey,
                'error_code' => $errorCode,
                'error' => $exception->getMessage(),
            ],
            idempotencyKey: "appraisal.normalization.failed:{$this->requestEventId}",
            correlationId: $correlationId
        );
    }

    private function mapLimiterReason(string $reason): string
    {
        return match ($reason) {
            'CIRCUIT_OPEN' => 'AI_CIRCUIT_OPEN',
            'RATE_LIMITED' => 'AI_RATE_LIMITED',
            default => 'AI_RATE_LIMITED',
        };
    }
}
