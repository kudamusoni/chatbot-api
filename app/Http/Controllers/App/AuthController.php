<?php

namespace App\Http\Controllers\App;

use App\Enums\AppDenyReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\LoginRequest;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'error' => 'UNAUTHENTICATED',
                'reason_code' => AppDenyReason::UNAUTHENTICATED->value,
            ], 401);
        }

        $request->session()->regenerate();

        /** @var \App\Models\User $user */
        $user = $request->user();

        if (!$user->isPlatformAdmin()) {
            $clientIds = $user->clients()->pluck('clients.id');
            if ($clientIds->count() === 1) {
                $request->session()->put('active_client_id', $clientIds->first());
            }
        }

        return response()->json($this->bootPayload($request));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return response()->json($this->bootPayload($request));
    }

    private function bootPayload(Request $request): array
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $activeClientId = $request->session()->get('active_client_id');
        $activeClient = null;
        $tenantRole = null;

        if (is_string($activeClientId) && $activeClientId !== '') {
            $client = Client::query()
                ->select('id', 'name')
                ->find($activeClientId);

            if ($client && ($user->isPlatformAdmin() || $user->hasAccessToClient($client->id))) {
                $activeClient = [
                    'id' => $client->id,
                    'name' => $client->name,
                ];
                $tenantRole = $user->roleForClient($client->id);
            } else {
                $activeClientId = null;
            }
        } else {
            $activeClientId = null;
        }

        $hasManageTenantRights = in_array($tenantRole, ['owner', 'admin'], true);
        $hasActiveClient = $activeClient !== null;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'platform_role' => $user->platform_role,
            ],
            'active_client_id' => $activeClientId,
            'active_client' => $activeClient,
            'tenant_role' => $tenantRole,
            'permissions' => [
                'can_manage_settings' => $hasActiveClient && ($user->isSuperAdmin() || $hasManageTenantRights),
                'can_manage_questions' => $hasActiveClient && ($user->isSuperAdmin() || $hasManageTenantRights),
                'can_export_leads' => $hasActiveClient && ($user->isPlatformAdmin() || $tenantRole !== null),
                'can_manage_imports' => $hasActiveClient && ($user->isSuperAdmin() || $hasManageTenantRights),
            ],
        ];
    }
}
