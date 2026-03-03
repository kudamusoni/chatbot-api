<?php

namespace Tests\Feature\Http\App;

use App\Enums\AppDenyReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_update_profile(): void
    {
        $this->patchJson('/app/account/profile', ['name' => 'New Name'])
            ->assertStatus(401)
            ->assertJsonPath('reason_code', AppDenyReason::UNAUTHENTICATED->value);
    }

    public function test_profile_update_validates_name(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user, 'web')
            ->patchJson('/app/account/profile', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_verified_user_can_update_profile_and_get_boot_payload(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user, 'web')
            ->patchJson('/app/account/profile', ['name' => 'New Name'])
            ->assertOk();

        $user->refresh();
        $this->assertSame('New Name', $user->name);

        $response->assertJsonPath('user.id', $user->id);
        $response->assertJsonPath('user.name', 'New Name');
        $response->assertJsonPath('requires_email_verification', false);
        $response->assertJsonStructure([
            'user',
            'requires_email_verification',
            'active_client_id',
            'active_client',
            'tenant_role',
            'permissions',
        ]);
    }
}

