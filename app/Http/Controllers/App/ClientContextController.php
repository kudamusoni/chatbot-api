<?php

namespace App\Http\Controllers\App;

use App\Enums\AppDenyReason;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientContextController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->isPlatformAdmin()) {
            $clients = Client::query()
                ->select('id', 'name', 'slug')
                ->orderBy('name')
                ->get();
        } else {
            $clients = $user->clients()
                ->select('clients.id', 'clients.name', 'clients.slug')
                ->orderBy('clients.name')
                ->get();
        }

        return response()->json(['clients' => $clients]);
    }

    public function switch(Request $request, Client $client): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if (!$user->isPlatformAdmin() && !$user->hasAccessToClient($client->id)) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'reason_code' => AppDenyReason::NOT_A_CLIENT_MEMBER->value,
            ], 403);
        }

        $fromClientId = $request->session()->get('active_client_id');
        $request->session()->put('active_client_id', $client->id);

        $this->auditLogger->log($user, 'client.switched', $client->id, [
            'from_client_id' => $fromClientId,
            'to_client_id' => $client->id,
        ]);

        return response()->json([
            'ok' => true,
            'active_client_id' => $client->id,
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $request->session()->forget('active_client_id');

        return response()->json(['ok' => true]);
    }
}
