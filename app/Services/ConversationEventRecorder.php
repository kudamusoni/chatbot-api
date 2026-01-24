<?php

namespace App\Services;

use App\Enums\ConversationEventType;
use App\Events\Conversation\ConversationEventRecorded;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class ConversationEventRecorder
{
    /**
     * Record a new conversation event.
     *
     * If an idempotency key is provided and an event with that key already
     * exists for this conversation, the existing event is returned instead
     * of creating a duplicate.
     *
     * @param Conversation $conversation The conversation to record the event for
     * @param ConversationEventType $type The type of event
     * @param array $payload The event payload (required, not nullable)
     * @param string|null $idempotencyKey Optional key for idempotent operations
     * @param string|null $correlationId Optional correlation ID for linking related events
     * @return array{event: ConversationEvent, created: bool} The event and whether it was newly created
     */
    public function record(
        Conversation $conversation,
        ConversationEventType $type,
        array $payload,
        ?string $idempotencyKey = null,
        ?string $correlationId = null
    ): array {
        // If idempotency key provided, check for existing event first
        if ($idempotencyKey !== null) {
            $existing = ConversationEvent::where('conversation_id', $conversation->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return ['event' => $existing, 'created' => false];
            }
        }

        // Generate correlation ID if not provided
        $correlationId ??= (string) Str::uuid();

        try {
            $event = ConversationEvent::create([
                'conversation_id' => $conversation->id,
                'client_id' => $conversation->client_id,
                'type' => $type,
                'payload' => $payload,
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Dispatch the event for projectors and other listeners
            ConversationEventRecorded::dispatch($event);

            return ['event' => $event, 'created' => true];
        } catch (UniqueConstraintViolationException $e) {
            // Race condition: another process created the event with this idempotency key
            // Fetch and return the existing event
            if ($idempotencyKey !== null) {
                $existing = ConversationEvent::where('conversation_id', $conversation->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();

                return ['event' => $existing, 'created' => false];
            }

            // If no idempotency key, re-throw the exception
            throw $e;
        }
    }

    /**
     * Record a user message event.
     */
    public function recordUserMessage(
        Conversation $conversation,
        string $content,
        ?string $idempotencyKey = null,
        ?string $correlationId = null
    ): array {
        return $this->record(
            $conversation,
            ConversationEventType::USER_MESSAGE_CREATED,
            ['content' => $content],
            $idempotencyKey,
            $correlationId
        );
    }

    /**
     * Record an assistant message event.
     */
    public function recordAssistantMessage(
        Conversation $conversation,
        string $content,
        ?string $correlationId = null
    ): array {
        return $this->record(
            $conversation,
            ConversationEventType::ASSISTANT_MESSAGE_CREATED,
            ['content' => $content],
            correlationId: $correlationId
        );
    }

    /**
     * Record a valuation requested event.
     */
    public function recordValuationRequested(
        Conversation $conversation,
        array $valuationData,
        ?string $idempotencyKey = null,
        ?string $correlationId = null
    ): array {
        return $this->record(
            $conversation,
            ConversationEventType::VALUATION_REQUESTED,
            $valuationData,
            $idempotencyKey,
            $correlationId
        );
    }

    /**
     * Record a valuation completed event.
     */
    public function recordValuationCompleted(
        Conversation $conversation,
        array $result,
        ?string $correlationId = null
    ): array {
        return $this->record(
            $conversation,
            ConversationEventType::VALUATION_COMPLETED,
            ['result' => $result],
            correlationId: $correlationId
        );
    }
}
