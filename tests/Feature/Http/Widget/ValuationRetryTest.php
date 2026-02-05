<?php

namespace Tests\Feature\Http\Widget;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Enums\ValuationStatus;
use App\Models\ConversationEvent;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class ValuationRetryTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_retry_emits_valuation_requested_and_resets_status(): void
    {
        Bus::fake();

        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_FAILED,
        ]);

        // Create a failed valuation
        $inputSnapshot = ['maker' => 'Royal Doulton', 'age' => 'circa 1950'];
        $snapshotHash = Valuation::generateSnapshotHash($inputSnapshot);

        $valuation = Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::FAILED,
            'snapshot_hash' => $snapshotHash,
            'input_snapshot' => $inputSnapshot,
            'result' => ['error' => 'Test error'],
        ]);

        $actionId = (string) Str::uuid();

        $response = $this->postJson('/api/widget/valuation/retry', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        // Check valuation.requested event was emitted
        $events = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::VALUATION_REQUESTED)
            ->get();

        $this->assertCount(1, $events);
        $this->assertTrue($events->first()->payload['retry'] ?? false);

        // Check valuation was reset to PENDING
        $valuation->refresh();
        $this->assertEquals(ValuationStatus::PENDING, $valuation->status);
        $this->assertNull($valuation->result);
    }

    public function test_retry_is_idempotent_by_action_id(): void
    {
        Bus::fake();

        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_FAILED,
        ]);

        $inputSnapshot = ['maker' => 'Royal Doulton'];
        $snapshotHash = Valuation::generateSnapshotHash($inputSnapshot);

        Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::FAILED,
            'snapshot_hash' => $snapshotHash,
            'input_snapshot' => $inputSnapshot,
        ]);

        $actionId = (string) Str::uuid();
        $payload = [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => $actionId,
        ];

        // First call
        $response1 = $this->postJson('/api/widget/valuation/retry', $payload);
        $response1->assertOk();

        // Simulate state change (job ran and completed)
        $conversation->update(['state' => ConversationState::VALUATION_RUNNING]);

        // Second call with same action_id should still succeed (idempotent)
        $response2 = $this->postJson('/api/widget/valuation/retry', $payload);
        $response2->assertOk();

        // Should only have 1 valuation.requested event
        $eventCount = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::VALUATION_REQUESTED)
            ->count();

        $this->assertEquals(1, $eventCount);
    }

    public function test_retry_returns_409_when_not_in_failed_state(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_RUNNING,
        ]);

        $response = $this->postJson('/api/widget/valuation/retry', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(409)
            ->assertJson(['error' => 'Cannot retry valuation in current state']);
    }

    public function test_retry_returns_409_when_no_failed_valuation_exists(): void
    {
        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_FAILED,
        ]);

        // No valuation created

        $response = $this->postJson('/api/widget/valuation/retry', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(409)
            ->assertJson(['error' => 'No failed valuation found to retry']);
    }

    public function test_retry_returns_401_for_invalid_token(): void
    {
        $client = $this->makeClient();

        $response = $this->postJson('/api/widget/valuation/retry', [
            'client_id' => $client->id,
            'session_token' => 'invalid-token',
            'action_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(401);
    }

    public function test_retry_token_from_other_client_is_rejected(): void
    {
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();

        [$conversationA, $tokenA] = $this->makeConversation($clientA, [
            'state' => ConversationState::VALUATION_FAILED,
        ]);

        // Try to use clientA's token with clientB
        $response = $this->postJson('/api/widget/valuation/retry', [
            'client_id' => $clientB->id,
            'session_token' => $tokenA,
            'action_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(401);
    }

    public function test_retry_transitions_state_to_valuation_running(): void
    {
        Bus::fake();

        $client = $this->makeClient();
        [$conversation, $token] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_FAILED,
        ]);

        $inputSnapshot = ['maker' => 'Royal Doulton'];
        $snapshotHash = Valuation::generateSnapshotHash($inputSnapshot);

        Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::FAILED,
            'snapshot_hash' => $snapshotHash,
            'input_snapshot' => $inputSnapshot,
        ]);

        $response = $this->postJson('/api/widget/valuation/retry', [
            'client_id' => $client->id,
            'session_token' => $token,
            'action_id' => (string) Str::uuid(),
        ]);

        $response->assertOk();

        // State should transition to VALUATION_RUNNING via projector
        $conversation->refresh();
        $this->assertEquals(ConversationState::VALUATION_RUNNING, $conversation->state);
    }
}
