<?php

namespace App\Jobs;

use App\Enums\ConversationEventType;
use App\Models\AiRequest;
use App\Models\ClientSetting;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\AiUsageLimiter;
use App\Services\Ai\ChatPromptBuilder;
use App\Services\ConversationEventRecorder;
use App\Services\WidgetIntroMessageResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunAssistantResponseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly string $clientId,
        public readonly string $conversationId,
        public readonly int $requestEventId,
        public readonly string $turnId
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        AiClient $aiClient,
        AiUsageLimiter $limiter,
        ChatPromptBuilder $promptBuilder,
        ConversationEventRecorder $eventRecorder,
        WidgetIntroMessageResolver $fallbackResolver
    ): void {
        $conversation = Conversation::query()->where('id', $this->conversationId)->first();
        if (!$conversation || (string) $conversation->client_id !== $this->clientId) {
            Log::warning('RunAssistantResponseJob tenant mismatch', [
                'client_id' => $this->clientId,
                'conversation_id' => $this->conversationId,
            ]);

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
        if (!config('ai.enabled') || !$settings->ai_enabled) {
            $eventRecorder->record(
                $conversation,
                ConversationEventType::ASSISTANT_RESPONSE_FAILED,
                [
                    'turn_id' => $this->turnId,
                    'request_event_id' => $this->requestEventId,
                    'error_code' => 'AI_DISABLED_BY_CLIENT_FLAG',
                ],
                idempotencyKey: "assistant.response.failed:{$this->requestEventId}",
                correlationId: $requestEvent->correlation_id
            );

            $eventRecorder->record(
                $conversation,
                ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                [
                    'content' => $fallbackResolver->forFallback($this->clientId),
                    'turn_id' => $this->turnId,
                ],
                idempotencyKey: "assistant.message:{$this->requestEventId}",
                correlationId: $requestEvent->correlation_id
            );

            return;
        }

        $limiterDecision = $limiter->allow($this->clientId, null);
        if (!$limiterDecision['allowed']) {
            $eventRecorder->record(
                $conversation,
                ConversationEventType::ASSISTANT_RESPONSE_FAILED,
                [
                    'turn_id' => $this->turnId,
                    'request_event_id' => $this->requestEventId,
                    'error_code' => $this->mapLimiterReason((string) $limiterDecision['reason']),
                ],
                idempotencyKey: "assistant.response.failed:{$this->requestEventId}",
                correlationId: $requestEvent->correlation_id
            );

            $eventRecorder->record(
                $conversation,
                ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                [
                    'content' => (string) config('ai.fallback.assistant_message'),
                    'turn_id' => $this->turnId,
                ],
                idempotencyKey: "assistant.message:{$this->requestEventId}",
                correlationId: $requestEvent->correlation_id
            );

            return;
        }

        $messages = $promptBuilder->build($conversation);
        $aiRequest = AiRequest::query()->firstOrCreate(
            [
                'client_id' => $this->clientId,
                'conversation_id' => $this->conversationId,
                'event_id' => $this->requestEventId,
                'purpose' => 'CHAT',
            ],
            [
                'provider' => (string) config('ai.provider', 'openai'),
                'model' => (string) config('ai.models.chat'),
                'prompt_version' => (string) config('ai.prompt_versions.chat'),
                'policy_version' => config('ai.policy_version'),
                'prompt_hash' => hash('sha256', json_encode($messages)),
                'status' => 'PENDING',
            ]
        );

        try {
            $result = $aiClient->chat($messages, [
                'model' => config('ai.models.chat'),
                'temperature' => config('ai.temperature'),
                'max_output_tokens' => config('ai.max_output_tokens'),
            ]);

            $eventRecorder->record(
                $conversation,
                ConversationEventType::ASSISTANT_RESPONSE_COMPLETED,
                [
                    'turn_id' => $this->turnId,
                    'request_event_id' => $this->requestEventId,
                    'ai_request_id' => $aiRequest->id,
                ],
                idempotencyKey: "assistant.response.completed:{$this->requestEventId}",
                correlationId: $requestEvent->correlation_id
            );

            $eventRecorder->record(
                $conversation,
                ConversationEventType::ASSISTANT_MESSAGE_CREATED,
                [
                    'content' => $result->content,
                    'turn_id' => $this->turnId,
                ],
                idempotencyKey: "assistant.message:{$this->requestEventId}",
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

        /** @var ConversationEventRecorder $eventRecorder */
        $eventRecorder = app(ConversationEventRecorder::class);
        $errorCode = $exception instanceof AiException ? $exception->errorCode : 'AI_PROVIDER_ERROR';

        $eventRecorder->record(
            $conversation,
            ConversationEventType::ASSISTANT_RESPONSE_FAILED,
            [
                'turn_id' => $this->turnId,
                'request_event_id' => $this->requestEventId,
                'error_code' => $errorCode,
                'error' => $exception->getMessage(),
            ],
            idempotencyKey: "assistant.response.failed:{$this->requestEventId}",
            correlationId: $correlationId
        );

        $eventRecorder->record(
            $conversation,
            ConversationEventType::ASSISTANT_MESSAGE_CREATED,
            [
                'content' => (string) config('ai.fallback.assistant_message'),
                'turn_id' => $this->turnId,
            ],
            idempotencyKey: "assistant.message:{$this->requestEventId}",
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
