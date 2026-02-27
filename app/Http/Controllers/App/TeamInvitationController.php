<?php

namespace App\Http\Controllers\App;

use App\Enums\AppDenyReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\CreateInvitationRequest;
use App\Models\ClientInvitation;
use App\Services\AuditLogger;
use App\Services\InviteService;
use App\Support\CurrentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamInvitationController extends Controller
{
    public function __construct(
        private readonly InviteService $inviteService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        $invites = ClientInvitation::query()
            ->where('client_id', $currentClient->id())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ClientInvitation $invite) => [
                'id' => $invite->id,
                'email' => $invite->email,
                'role' => $invite->role,
                'status' => $this->statusFor($invite),
                'accepted_at' => $invite->accepted_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'expires_at' => $invite->expires_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'revoked_at' => $invite->revoked_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'created_at' => $invite->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ])
            ->values();

        return response()->json(['data' => $invites]);
    }

    public function store(CreateInvitationRequest $request): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        try {
            $result = $this->inviteService->createOrRotateInvite(
                $currentClient->client,
                (string) $request->validated('email'),
                (string) $request->validated('role'),
                (int) $actor->id
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === AppDenyReason::ALREADY_MEMBER->value) {
                return response()->json([
                    'error' => 'CONFLICT',
                    'reason_code' => AppDenyReason::ALREADY_MEMBER->value,
                ], 409);
            }

            throw $e;
        }

        /** @var ClientInvitation $invite */
        $invite = $result['invite'];
        $action = ($result['was_rotated'] ?? false) ? 'invite.resent' : 'invite.created';
        $this->auditLogger->log($actor, $action, $currentClient->id(), [
            'invitation_id' => $invite->id,
            'email' => $invite->email,
            'role' => $invite->role,
            'expires_at' => $invite->expires_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        return response()->json([
            'ok' => true,
            'invitation_id' => $invite->id,
            'expires_at' => $invite->expires_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        $invite = ClientInvitation::query()
            ->where('client_id', $currentClient->id())
            ->where('id', $id)
            ->firstOrFail();

        $invite = $this->inviteService->revokeInvite($invite);

        $this->auditLogger->log($actor, 'invite.revoked', $currentClient->id(), [
            'invitation_id' => $invite->id,
            'email' => $invite->email,
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    private function statusFor(ClientInvitation $invite): string
    {
        if ($invite->accepted_at !== null) {
            return 'accepted';
        }
        if ($invite->revoked_at !== null) {
            return 'revoked';
        }
        if ($invite->expires_at?->isPast()) {
            return 'expired';
        }

        return 'pending';
    }
}

