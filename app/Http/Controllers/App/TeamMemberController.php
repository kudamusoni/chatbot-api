<?php

namespace App\Http\Controllers\App;

use App\Enums\AppDenyReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\UpdateMemberRoleRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CurrentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamMemberController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        $members = $currentClient->client
            ->users()
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => (string) $user->pivot->role,
                'joined_at' => $user->pivot->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ])
            ->values();

        return response()->json(['data' => $members]);
    }

    public function updateRole(UpdateMemberRoleRequest $request, int $userId): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        if ($currentClient->role !== 'owner' && !$actor->isSuperAdmin()) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::INSUFFICIENT_ROLE->value,
            ], 403);
        }

        $membership = DB::table('client_user')
            ->where('client_id', $currentClient->id())
            ->where('user_id', $userId)
            ->first();

        if (!$membership) {
            abort(404);
        }

        if ((string) $membership->role === 'owner') {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::INSUFFICIENT_ROLE->value,
            ], 403);
        }

        $newRole = (string) $request->validated('role');
        DB::table('client_user')
            ->where('client_id', $currentClient->id())
            ->where('user_id', $userId)
            ->update([
                'role' => $newRole,
                'updated_at' => now(),
            ]);

        $this->auditLogger->log($actor, 'client.member.role_updated', $currentClient->id(), [
            'target_user_id' => $userId,
            'from_role' => (string) $membership->role,
            'to_role' => $newRole,
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function remove(Request $request, int $userId): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        /** @var \App\Models\User $actor */
        $actor = $request->user();

        if ($currentClient->role !== 'owner' && !$actor->isSuperAdmin()) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::INSUFFICIENT_ROLE->value,
            ], 403);
        }

        if ((int) $actor->id === $userId) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::INSUFFICIENT_ROLE->value,
            ], 403);
        }

        $membership = DB::table('client_user')
            ->where('client_id', $currentClient->id())
            ->where('user_id', $userId)
            ->first();

        if (!$membership) {
            abort(404);
        }

        $ownerCount = DB::table('client_user')
            ->where('client_id', $currentClient->id())
            ->where('role', 'owner')
            ->count();

        if ((string) $membership->role === 'owner' && $ownerCount <= 1) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::INSUFFICIENT_ROLE->value,
            ], 403);
        }

        DB::table('client_user')
            ->where('client_id', $currentClient->id())
            ->where('user_id', $userId)
            ->delete();

        $this->auditLogger->log($actor, 'client.member.removed', $currentClient->id(), [
            'target_user_id' => $userId,
            'removed_role' => (string) $membership->role,
            'ip' => $request->ip(),
            'ua' => (string) $request->userAgent(),
        ]);

        return response()->json(['ok' => true]);
    }
}
