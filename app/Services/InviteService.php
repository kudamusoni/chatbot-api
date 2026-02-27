<?php

namespace App\Services;

use App\Mail\ClientInvitationMail;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InviteService
{
    /**
     * @return array{invite:ClientInvitation,raw_token:string,was_rotated:bool}
     */
    public function createOrRotateInvite(Client $client, string $email, string $role, int $invitedByUserId): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $emailHash = hash('sha256', $normalizedEmail);

        $existingUser = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($normalizedEmail)])
            ->first();

        if ($existingUser) {
            $isMember = DB::table('client_user')
                ->where('client_id', $client->id)
                ->where('user_id', $existingUser->id)
                ->exists();
            if ($isMember) {
                throw new \RuntimeException('ALREADY_MEMBER');
            }
        }

        $rawToken = Str::random(64);
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = CarbonImmutable::now('UTC')->addDays(7);

        $pendingInvite = ClientInvitation::query()
            ->where('client_id', $client->id)
            ->where('email_hash', $emailHash)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        $wasRotated = $pendingInvite !== null;

        $invite = DB::transaction(function () use (
            $pendingInvite,
            $client,
            $normalizedEmail,
            $emailHash,
            $role,
            $tokenHash,
            $invitedByUserId,
            $expiresAt
        ): ClientInvitation {
            if ($pendingInvite) {
                $pendingInvite->update([
                    'role' => $role,
                    'token_hash' => $tokenHash,
                    'invited_by_user_id' => $invitedByUserId,
                    'expires_at' => $expiresAt,
                ]);

                return $pendingInvite->fresh();
            }

            return ClientInvitation::create([
                'client_id' => $client->id,
                'email' => $normalizedEmail,
                'email_hash' => $emailHash,
                'role' => $role,
                'token_hash' => $tokenHash,
                'invited_by_user_id' => $invitedByUserId,
                'expires_at' => $expiresAt,
            ]);
        });

        $acceptUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/')
            . '/accept-invite?token='
            . urlencode($rawToken);

        Mail::to($normalizedEmail)->queue(new ClientInvitationMail(
            (string) $client->name,
            $role,
            $acceptUrl,
            $expiresAt->format('Y-m-d\TH:i:s\Z')
        ));

        return [
            'invite' => $invite,
            'raw_token' => $rawToken,
            'was_rotated' => $wasRotated,
        ];
    }

    /**
     * @return array{ok:bool,reason?:string,status?:int,invite?:ClientInvitation,user?:User,new_user?:bool,membership_created?:bool}
     */
    public function acceptInvite(string $rawToken, ?string $name = null, ?string $password = null): array
    {
        $tokenHash = hash('sha256', $rawToken);
        /** @var ClientInvitation|null $invite */
        $invite = ClientInvitation::query()
            ->with('client')
            ->where('token_hash', $tokenHash)
            ->first();

        if (!$invite) {
            return ['ok' => false, 'reason' => 'INVITE_INVALID', 'status' => 404];
        }

        if ($invite->accepted_at !== null) {
            return ['ok' => false, 'reason' => 'INVITE_ACCEPTED', 'status' => 409];
        }

        if ($invite->revoked_at !== null) {
            return ['ok' => false, 'reason' => 'INVITE_REVOKED', 'status' => 410];
        }

        if ($invite->expires_at !== null && $invite->expires_at->isPast()) {
            return ['ok' => false, 'reason' => 'INVITE_EXPIRED', 'status' => 410];
        }

        $normalizedEmail = $this->normalizeEmail($invite->email);
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($normalizedEmail)])
            ->first();

        $newUser = false;
        $membershipCreated = false;

        DB::transaction(function () use (
            &$user,
            &$newUser,
            &$membershipCreated,
            $invite,
            $normalizedEmail,
            $name,
            $password
        ): void {
            if (!$user) {
                if (!$name || !$password) {
                    throw new \InvalidArgumentException('NAME_AND_PASSWORD_REQUIRED');
                }

                $user = User::create([
                    'name' => $name,
                    'email' => $normalizedEmail,
                    'password' => $password,
                    'platform_role' => 'none',
                ]);
                $newUser = true;
            }

            $existingMembership = DB::table('client_user')
                ->where('client_id', $invite->client_id)
                ->where('user_id', $user->id)
                ->exists();

            if (!$existingMembership) {
                DB::table('client_user')->insert([
                    'client_id' => $invite->client_id,
                    'user_id' => $user->id,
                    'role' => $invite->role,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $membershipCreated = true;
            }

            $invite->accepted_at = now();
            $invite->save();
        });

        return [
            'ok' => true,
            'invite' => $invite->fresh(),
            'user' => $user,
            'new_user' => $newUser,
            'membership_created' => $membershipCreated,
        ];
    }

    public function revokeInvite(ClientInvitation $invite): ClientInvitation
    {
        if ($invite->revoked_at === null) {
            $invite->revoked_at = now();
            $invite->save();
        }

        return $invite->fresh();
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}

