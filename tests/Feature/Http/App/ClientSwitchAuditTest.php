<?php

namespace Tests\Feature\Http\App;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientSwitchAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_switches_clients_and_audit_log_tracks_from_to(): void
    {
        $user = User::factory()->create(['platform_role' => 'super_admin']);
        $clientA = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $clientB = Client::create(['name' => 'Client B', 'slug' => 'client-b', 'settings' => []]);
        [$conversationA] = Conversation::createWithToken($clientA->id);
        [$conversationB] = Conversation::createWithToken($clientB->id);

        Lead::create([
            'conversation_id' => $conversationA->id,
            'client_id' => $clientA->id,
            'name' => 'Lead A',
            'email' => 'a@example.com',
            'email_hash' => hash('sha256', 'a@example.com'),
            'phone_raw' => '+447700900001',
            'phone_normalized' => '+447700900001',
            'phone_hash' => hash('sha256', '+447700900001'),
            'status' => 'REQUESTED',
        ]);

        Lead::create([
            'conversation_id' => $conversationB->id,
            'client_id' => $clientB->id,
            'name' => 'Lead B',
            'email' => 'b@example.com',
            'email_hash' => hash('sha256', 'b@example.com'),
            'phone_raw' => '+447700900002',
            'phone_normalized' => '+447700900002',
            'phone_hash' => hash('sha256', '+447700900002'),
            'status' => 'REQUESTED',
        ]);

        $this->actingAs($user, 'web')
            ->postJson("/app/clients/{$clientA->id}/switch")
            ->assertOk();

        $this->actingAs($user, 'web')
            ->getJson('/app/leads')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Lead A'])
            ->assertJsonMissing(['name' => 'Lead B']);

        $this->actingAs($user, 'web')
            ->postJson("/app/clients/{$clientB->id}/switch")
            ->assertOk();

        $this->actingAs($user, 'web')
            ->getJson('/app/leads')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Lead B'])
            ->assertJsonMissing(['name' => 'Lead A']);

        $logs = AuditLog::where('action', 'client.switched')->orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame($clientA->id, $logs[0]->meta['to_client_id']);
        $this->assertNull($logs[0]->meta['from_client_id']);
        $this->assertSame($clientA->id, $logs[1]->meta['from_client_id']);
        $this->assertSame($clientB->id, $logs[1]->meta['to_client_id']);
    }
}
