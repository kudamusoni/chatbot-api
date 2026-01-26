<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * List clients the authenticated user has access to.
     *
     * GET /api/admin/clients
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get only clients the user has access to via the pivot table
        $clients = $user->clients()
            ->select(['clients.id', 'clients.name', 'clients.slug', 'clients.created_at'])
            ->withPivot('role')
            ->get()
            ->map(fn ($client) => [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'role' => $client->pivot->role,
                'created_at' => $client->created_at->toIso8601String(),
            ]);

        return response()->json([
            'clients' => $clients,
        ]);
    }
}
