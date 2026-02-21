<?php

namespace App\Http\Middleware;

use App\Enums\AppDenyReason;
use App\Models\Client;
use App\Support\CurrentClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'UNAUTHENTICATED',
                'reason_code' => AppDenyReason::UNAUTHENTICATED->value,
            ], 401);
        }

        $activeClientId = $request->session()->get('active_client_id');

        if (!is_string($activeClientId) || $activeClientId === '') {
            return $this->noActiveClient();
        }

        $client = Client::find($activeClientId);
        if (!$client) {
            return $this->noActiveClient();
        }

        $isPlatform = $user->isPlatformAdmin();
        $role = $isPlatform ? 'platform' : $user->roleForClient($client->id);

        if (!$isPlatform && !$role) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::NOT_A_CLIENT_MEMBER->value,
            ], 403);
        }

        app()->instance(CurrentClient::class, new CurrentClient(
            $client,
            $role,
            $isPlatform
        ));

        return $next($request);
    }

    private function noActiveClient(): JsonResponse
    {
        return response()->json([
            'error' => 'CONFLICT',
            'reason_code' => AppDenyReason::NO_ACTIVE_CLIENT->value,
        ], 409);
    }
}
