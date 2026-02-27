<?php

namespace Tests\Feature\Http\App;

use App\Mail\ClientInvitationMail;
use App\Mail\VerifyEmailMail;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_invitation_and_mail_is_queued(): void
    {
        Mail::fake();

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $owner = User::factory()->create();
        $owner->clients()->attach($client->id, ['role' => 'owner']);

        $response = $this->actingAs($owner, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson('/app/team/invitations', [
                'email' => 'invitee@example.com',
                'role' => 'admin',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $inviteId = $response->json('invitation_id');
        $this->assertNotNull($inviteId);
        $this->assertDatabaseHas('client_invitations', [
            'id' => $inviteId,
            'client_id' => $client->id,
            'email' => 'invitee@example.com',
            'role' => 'admin',
        ]);
        Mail::assertQueued(ClientInvitationMail::class);
    }

    public function test_invite_for_existing_member_returns_already_member(): void
    {
        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'member@example.com']);
        $owner->clients()->attach($client->id, ['role' => 'owner']);
        $member->clients()->attach($client->id, ['role' => 'viewer']);

        $this->actingAs($owner, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson('/app/team/invitations', [
                'email' => 'member@example.com',
                'role' => 'admin',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'ALREADY_MEMBER');
    }

    public function test_pending_invite_for_same_email_is_rotated_not_duplicated(): void
    {
        Mail::fake();

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $owner = User::factory()->create();
        $owner->clients()->attach($client->id, ['role' => 'owner']);

        $this->actingAs($owner, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson('/app/team/invitations', [
                'email' => 'invitee@example.com',
                'role' => 'viewer',
            ])
            ->assertOk();

        $first = ClientInvitation::query()->where('client_id', $client->id)->firstOrFail();
        $firstTokenHash = $first->token_hash;

        $this->actingAs($owner, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson('/app/team/invitations', [
                'email' => 'invitee@example.com',
                'role' => 'admin',
            ])
            ->assertOk();

        $this->assertSame(1, ClientInvitation::query()->where('client_id', $client->id)->count());
        $rotated = ClientInvitation::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertNotSame($firstTokenHash, $rotated->token_hash);
        $this->assertSame('admin', $rotated->role);
    }

    public function test_accept_invite_creates_user_membership_and_queues_verification_mail(): void
    {
        Mail::fake();

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $owner = User::factory()->create();
        $owner->clients()->attach($client->id, ['role' => 'owner']);

        $this->actingAs($owner, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson('/app/team/invitations', [
                'email' => 'newinvite@example.com',
                'role' => 'viewer',
            ])
            ->assertOk();

        $queuedMail = null;
        Mail::assertQueued(ClientInvitationMail::class, function (ClientInvitationMail $mail) use (&$queuedMail): bool {
            $queuedMail = $mail;
            return true;
        });
        $this->assertNotNull($queuedMail);
        parse_str((string) parse_url($queuedMail->acceptUrl, PHP_URL_QUERY), $qs);
        $token = $qs['token'] ?? null;
        $this->assertNotNull($token);

        $response = $this->postJson('/app/onboarding/invitations/accept', [
            'token' => $token,
            'name' => 'Invitee User',
            'password' => 'password123',
        ])->assertOk();

        $user = User::query()->where('email', 'newinvite@example.com')->first();
        $this->assertNotNull($user);
        $this->assertDatabaseHas('client_user', [
            'client_id' => $client->id,
            'user_id' => $user->id,
            'role' => 'viewer',
        ]);

        $invite = ClientInvitation::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertNotNull($invite->accepted_at);
        $response->assertJsonPath('requires_email_verification', true);
        Mail::assertQueued(VerifyEmailMail::class);
    }

    public function test_accept_invite_for_existing_user_attaches_membership(): void
    {
        Mail::fake();

        $client = Client::create(['name' => 'Client A', 'slug' => 'client-a', 'settings' => []]);
        $owner = User::factory()->create();
        $existing = User::factory()->create([
            'email' => 'existing@example.com',
        ]);
        $owner->clients()->attach($client->id, ['role' => 'owner']);

        $this->actingAs($owner, 'web')
            ->withSession(['active_client_id' => $client->id])
            ->postJson('/app/team/invitations', [
                'email' => 'existing@example.com',
                'role' => 'admin',
            ])
            ->assertOk();

        $queuedMail = null;
        Mail::assertQueued(ClientInvitationMail::class, function (ClientInvitationMail $mail) use (&$queuedMail): bool {
            $queuedMail = $mail;
            return true;
        });
        parse_str((string) parse_url($queuedMail->acceptUrl, PHP_URL_QUERY), $qs);
        $token = $qs['token'] ?? null;

        $this->postJson('/app/onboarding/invitations/accept', [
            'token' => $token,
        ])->assertOk();

        $this->assertDatabaseHas('client_user', [
            'client_id' => $client->id,
            'user_id' => $existing->id,
            'role' => 'admin',
        ]);
    }
}
