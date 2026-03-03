<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;

class AppBootPayloadService
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(Request $request, User $user): array
    {
        $accessibleClientsCount = $user->isPlatformAdmin()
            ? Client::query()->count()
            : $user->clients()->count();

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

        $isVerified = $user->email_verified_at !== null;

        if (!$isVerified) {
            return [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'platform_role' => $user->platform_role,
                    'verified' => false,
                ],
                'requires_email_verification' => true,
                'active_client_id' => $activeClientId,
                'active_client' => $activeClient,
                'accessible_clients_count' => $accessibleClientsCount,
                'tenant_role' => $tenantRole,
                'permissions' => [],
            ];
        }

        $hasManageTenantRights = in_array($tenantRole, ['owner', 'admin'], true);
        $hasActiveClient = $activeClient !== null;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'platform_role' => $user->platform_role,
                'verified' => true,
            ],
            'requires_email_verification' => false,
            'active_client_id' => $activeClientId,
            'active_client' => $activeClient,
            'accessible_clients_count' => $accessibleClientsCount,
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
