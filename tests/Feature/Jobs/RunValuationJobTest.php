<?php

namespace Tests\Feature\Jobs;

use App\Enums\ConversationEventType;
use App\Enums\ConversationState;
use App\Enums\ProductSource;
use App\Enums\ValuationStatus;
use App\Jobs\RunValuationJob;
use App\Models\ConversationEvent;
use App\Models\ProductCatalog;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithConversations;
use Tests\TestCase;

class RunValuationJobTest extends TestCase
{
    use RefreshDatabase, InteractsWithConversations;

    public function test_creates_valuation_completed_event(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_RUNNING,
        ]);

        // Create some comps
        $this->createComp($client, ['title' => 'Royal Doulton Vase', 'price' => 10000]);
        $this->createComp($client, ['title' => 'Royal Doulton Plate', 'price' => 20000]);

        // Create a pending valuation
        $valuation = Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::PENDING,
            'snapshot_hash' => Valuation::generateSnapshotHash(['maker' => 'Royal Doulton']),
            'input_snapshot' => ['maker' => 'Royal Doulton'],
        ]);

        // Run the job
        $job = new RunValuationJob($valuation->id);
        $job->handle(
            app(\App\Services\ValuationEngine::class),
            app(\App\Services\ConversationEventRecorder::class)
        );

        // Should create valuation.completed event
        $completedEvent = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::VALUATION_COMPLETED)
            ->first();

        $this->assertNotNull($completedEvent);
        $this->assertEquals($valuation->snapshot_hash, $completedEvent->payload['snapshot_hash']);
        $this->assertEquals('COMPLETED', $completedEvent->payload['status']);
        $this->assertArrayHasKey('result', $completedEvent->payload);
    }

    public function test_updates_valuation_to_completed(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_RUNNING,
        ]);

        $this->createComp($client, ['title' => 'Royal Doulton Vase', 'price' => 15000]);

        $valuation = Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::PENDING,
            'snapshot_hash' => Valuation::generateSnapshotHash(['maker' => 'Royal Doulton']),
            'input_snapshot' => ['maker' => 'Royal Doulton'],
        ]);

        $job = new RunValuationJob($valuation->id);
        $job->handle(
            app(\App\Services\ValuationEngine::class),
            app(\App\Services\ConversationEventRecorder::class)
        );

        $valuation->refresh();

        $this->assertEquals(ValuationStatus::COMPLETED, $valuation->status);
        $this->assertNotNull($valuation->result);
        $this->assertArrayHasKey('count', $valuation->result);
        $this->assertArrayHasKey('median', $valuation->result);
    }

    public function test_updates_conversation_state_to_valuation_ready(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_RUNNING,
        ]);

        $this->createComp($client, ['title' => 'Royal Doulton Vase', 'price' => 15000]);

        $valuation = Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::PENDING,
            'snapshot_hash' => Valuation::generateSnapshotHash(['maker' => 'Royal Doulton']),
            'input_snapshot' => ['maker' => 'Royal Doulton'],
        ]);

        $job = new RunValuationJob($valuation->id);
        $job->handle(
            app(\App\Services\ValuationEngine::class),
            app(\App\Services\ConversationEventRecorder::class)
        );

        $conversation->refresh();

        $this->assertEquals(ConversationState::VALUATION_READY, $conversation->state);
    }

    public function test_running_twice_does_not_duplicate_events(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_RUNNING,
        ]);

        $this->createComp($client, ['title' => 'Royal Doulton Vase', 'price' => 15000]);

        $valuation = Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::PENDING,
            'snapshot_hash' => Valuation::generateSnapshotHash(['maker' => 'Royal Doulton']),
            'input_snapshot' => ['maker' => 'Royal Doulton'],
        ]);

        $engine = app(\App\Services\ValuationEngine::class);
        $eventRecorder = app(\App\Services\ConversationEventRecorder::class);

        // Run the job twice
        $job1 = new RunValuationJob($valuation->id);
        $job1->handle($engine, $eventRecorder);

        $job2 = new RunValuationJob($valuation->id);
        $job2->handle($engine, $eventRecorder);

        // Should only have 1 valuation.completed event
        $eventCount = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::VALUATION_COMPLETED)
            ->count();

        $this->assertEquals(1, $eventCount);
    }

    public function test_skips_already_completed_valuation(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_READY,
        ]);

        $valuation = Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::COMPLETED,
            'snapshot_hash' => Valuation::generateSnapshotHash(['maker' => 'Test']),
            'input_snapshot' => ['maker' => 'Test'],
            'result' => ['count' => 5, 'median' => 10000],
        ]);

        $job = new RunValuationJob($valuation->id);
        $job->handle(
            app(\App\Services\ValuationEngine::class),
            app(\App\Services\ConversationEventRecorder::class)
        );

        // Should not create any events
        $eventCount = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEventType::VALUATION_COMPLETED)
            ->count();

        $this->assertEquals(0, $eventCount);
    }

    public function test_skips_nonexistent_valuation(): void
    {
        // Use a valid UUID format that doesn't exist
        $job = new RunValuationJob('00000000-0000-0000-0000-000000000000');
        $job->handle(
            app(\App\Services\ValuationEngine::class),
            app(\App\Services\ConversationEventRecorder::class)
        );

        // Should not throw, just log and return
        $this->assertTrue(true);
    }

    public function test_result_contains_expected_structure(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_RUNNING,
        ]);

        // Create varied comps for meaningful result
        $this->createComp($client, ['title' => 'Royal Doulton A', 'price' => 10000, 'source' => ProductSource::SOLD]);
        $this->createComp($client, ['title' => 'Royal Doulton B', 'price' => 15000, 'source' => ProductSource::ASKING]);
        $this->createComp($client, ['title' => 'Royal Doulton C', 'price' => 20000, 'source' => ProductSource::ASKING]);

        $valuation = Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::PENDING,
            'snapshot_hash' => Valuation::generateSnapshotHash(['maker' => 'Royal Doulton']),
            'input_snapshot' => ['maker' => 'Royal Doulton'],
        ]);

        $job = new RunValuationJob($valuation->id);
        $job->handle(
            app(\App\Services\ValuationEngine::class),
            app(\App\Services\ConversationEventRecorder::class)
        );

        $valuation->refresh();
        $result = $valuation->result;

        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('range', $result);
        $this->assertArrayHasKey('median', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('data_quality', $result);
        $this->assertArrayHasKey('signals_used', $result);

        $this->assertEquals(3, $result['count']);
        $this->assertEquals('internal', $result['data_quality']);
    }

    public function test_zero_comps_returns_zero_match_result(): void
    {
        $client = $this->makeClient();
        [$conversation, ] = $this->makeConversation($client, [
            'state' => ConversationState::VALUATION_RUNNING,
        ]);

        // No comps created

        $valuation = Valuation::create([
            'conversation_id' => $conversation->id,
            'client_id' => $client->id,
            'status' => ValuationStatus::PENDING,
            'snapshot_hash' => Valuation::generateSnapshotHash(['maker' => 'NonExistent']),
            'input_snapshot' => ['maker' => 'NonExistent'],
        ]);

        $job = new RunValuationJob($valuation->id);
        $job->handle(
            app(\App\Services\ValuationEngine::class),
            app(\App\Services\ConversationEventRecorder::class)
        );

        $valuation->refresh();
        $result = $valuation->result;

        $this->assertEquals(0, $result['count']);
        $this->assertNull($result['median']);
        $this->assertNull($result['range']);
        $this->assertEquals(0, $result['confidence']);
    }

    /**
     * Helper to create a product catalog item.
     */
    private function createComp($client, array $attributes): ProductCatalog
    {
        return ProductCatalog::create(array_merge([
            'client_id' => $client->id,
            'title' => 'Test Item',
            'source' => ProductSource::ASKING,
            'price' => 10000,
            'currency' => 'GBP',
        ], $attributes));
    }
}
