<?php

namespace Tests\Feature\Http\App;

use App\Models\AiRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiUsageEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_usage_returns_client_scoped_metrics(): void
    {
        $clientA = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $clientB = Client::create(['name' => 'Client B', 'slug' => 'client-b', 'settings' => []]);
        $user = User::factory()->create();
        $user->clients()->attach($clientA->id, ['role' => 'viewer']);

        AiRequest::create([
            'client_id' => $clientA->id,
            'conversation_id' => \App\Models\Conversation::createWithToken($clientA->id)[0]->id,
            'purpose' => 'CHAT',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'prompt_version' => 'chat:v1',
            'prompt_hash' => hash('sha256', 'a'),
            'status' => 'COMPLETED',
            'cost_estimate_minor' => 42,
            'completed_at' => now(),
        ]);

        AiRequest::create([
            'client_id' => $clientB->id,
            'conversation_id' => \App\Models\Conversation::createWithToken($clientB->id)[0]->id,
            'purpose' => 'CHAT',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'prompt_version' => 'chat:v1',
            'prompt_hash' => hash('sha256', 'b'),
            'status' => 'FAILED',
            'cost_estimate_minor' => 10,
            'completed_at' => now(),
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['active_client_id' => $clientA->id])
            ->getJson('/app/ai/usage?range=all')
            ->assertOk()
            ->assertJsonPath('client.id', $clientA->id)
            ->assertJsonPath('total_requests', 1)
            ->assertJsonPath('chat_requests', 1)
            ->assertJsonPath('normalize_requests', 0)
            ->assertJsonPath('failed_requests', 0)
            ->assertJsonPath('failed_chat', 0)
            ->assertJsonPath('failed_normalize', 0)
            ->assertJsonPath('estimated_cost_minor', 42)
            ->assertJsonStructure([
                'range',
                'from',
                'to',
                'client' => ['id', 'name'],
                'total_requests',
                'chat_requests',
                'normalize_requests',
                'failed_requests',
                'failed_chat',
                'failed_normalize',
                'avg_latency_ms',
                'estimated_cost_minor',
            ]);
    }
}
