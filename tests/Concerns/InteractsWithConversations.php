<?php

namespace Tests\Concerns;

use App\Enums\ConversationEventType;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\ConversationEventRecorder;

/**
 * Test helper trait for conversation-related tests.
 * Provides factory methods to keep tests short and focused.
 */
trait InteractsWithConversations
{
    protected function makeClient(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'Test Client ' . uniqid(),
            'slug' => 'test-client-' . uniqid(),
            'settings' => [],
        ], $attributes));
    }

    /**
     * Create a conversation for a client.
     * Returns [Conversation, rawToken] tuple.
     *
     * @return array{0: Conversation, 1: string}
     */
    protected function makeConversation(Client $client, array $attributes = []): array
    {
        return Conversation::createWithToken($client->id, $attributes);
    }

    protected function getEventRecorder(): ConversationEventRecorder
    {
        return new ConversationEventRecorder();
    }

    /**
     * Record a user message and return the result.
     *
     * @return array{event: ConversationEvent, created: bool}
     */
    protected function recordUserMessage(
        Conversation $conversation,
        string $content,
        ?string $idempotencyKey = null,
        ?string $correlationId = null
    ): array {
        return $this->getEventRecorder()->recordUserMessage(
            $conversation,
            $content,
            $idempotencyKey,
            $correlationId
        );
    }

    /**
     * Record an assistant message and return the result.
     *
     * @return array{event: ConversationEvent, created: bool}
     */
    protected function recordAssistantMessage(
        Conversation $conversation,
        string $content,
        ?string $correlationId = null
    ): array {
        return $this->getEventRecorder()->recordAssistantMessage(
            $conversation,
            $content,
            $correlationId
        );
    }

    /**
     * Record a generic event and return the result.
     *
     * @return array{event: ConversationEvent, created: bool}
     */
    protected function recordEvent(
        Conversation $conversation,
        ConversationEventType $type,
        array $payload,
        ?string $idempotencyKey = null,
        ?string $correlationId = null
    ): array {
        return $this->getEventRecorder()->record(
            $conversation,
            $type,
            $payload,
            $idempotencyKey,
            $correlationId
        );
    }
}
