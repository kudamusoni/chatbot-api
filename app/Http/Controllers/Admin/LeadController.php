<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    /**
     * List leads for a client.
     *
     * GET /api/admin/leads?client_id=...
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
        ]);

        $clientId = $request->query('client_id');
        $user = $request->user();

        if (!$user->hasAccessToClient($clientId)) {
            return response()->json([
                'error' => 'Access denied to this client',
            ], 403);
        }

        $leads = Lead::forClient($clientId)
            ->orderByDesc('created_at')
            ->paginate(50);

        $items = collect($leads->items())
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'conversation_id' => $lead->conversation_id,
                'status' => $lead->status,
                'email_masked' => $this->maskEmail($lead->email),
                'phone_masked' => $this->maskPhone($lead->phone_normalized),
                'created_at' => $lead->created_at->toIso8601String(),
            ]);

        return response()->json([
            'leads' => $items,
            'pagination' => [
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
                'per_page' => $leads->perPage(),
                'total' => $leads->total(),
            ],
        ]);
    }

    /**
     * Get one lead with full details.
     *
     * GET /api/admin/leads/{lead}
     */
    public function show(Request $request, Lead $lead): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToClient($lead->client_id)) {
            return response()->json([
                'error' => 'Access denied to this request',
            ], 403);
        }

        return response()->json([
            'lead' => [
                'id' => $lead->id,
                'conversation_id' => $lead->conversation_id,
                'client_id' => $lead->client_id,
                'request_event_id' => $lead->request_event_id,
                'name' => $lead->name,
                'email' => $lead->email,
                'phone_raw' => $lead->phone_raw,
                'phone_normalized' => $lead->phone_normalized,
                'status' => $lead->status,
                'created_at' => $lead->created_at->toIso8601String(),
                'updated_at' => $lead->updated_at->toIso8601String(),
            ],
        ]);
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2) {
            return '***';
        }

        $local = $parts[0];
        $domain = $parts[1];
        $firstChar = $local !== '' ? substr($local, 0, 1) : '*';

        return "{$firstChar}***@{$domain}";
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $lastFour = substr($digits, -4);

        if ($lastFour === '') {
            return '***';
        }

        return "***-***-{$lastFour}";
    }
}
