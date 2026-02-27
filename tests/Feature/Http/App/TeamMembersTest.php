<?php

namespace Tests\Feature\Http\App;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_member_role_and_remove_member(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $owner->clients()->attach($client->id, ['role' => 'owner']);
        $member->clients()->attach($client->id, ['role' => 'viewer']);

        $this->actingAs($owner, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->patchJson("/app/team/members/{$member->id}", [
                'role' => 'admin',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('client_user', [
            'client_id' => $client->id,
            'user_id' => $member->id,
            'role' => 'admin',
        ]);

        $this->actingAs($owner, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/team/members/{$member->id}/remove")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('client_user', [
            'client_id' => $client->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_admin_cannot_update_or_remove_members(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $owner->clients()->attach($client->id, ['role' => 'owner']);
        $admin->clients()->attach($client->id, ['role' => 'admin']);
        $member->clients()->attach($client->id, ['role' => 'viewer']);

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->patchJson("/app/team/members/{$member->id}", [
                'role' => 'admin',
            ])
            ->assertStatus(403)
            ->assertJsonPath('reason_code', 'INSUFFICIENT_ROLE');

        $this->actingAs($admin, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson("/app/team/members/{$member->id}/remove")
            ->assertStatus(403)
            ->assertJsonPath('reason_code', 'INSUFFICIENT_ROLE');
    }
}

