<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\ConversationEvent;
use App\Support\CurrentClient;
use App\Support\DashboardRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AiUsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rangeLabel = (string) $request->query('range', '7d');
        $range = $this->resolveRange($rangeLabel);

        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $clientId = (string) $currentClient->id();

        $query = AiRequest::query()->where('client_id', $clientId);
        if ($range->from !== null) {
            $query->whereBetween('created_at', [$range->from, $range->to]);
        }

        $totalRequests = (clone $query)->count();
        $failedRequests = (clone $query)->where('status', 'FAILED')->count();
        $estimatedCostMinor = (int) ((clone $query)->sum('cost_estimate_minor') ?? 0);
        $chatRequests = (clone $query)->where('purpose', 'CHAT')->count();
        $normalizeRequests = (clone $query)->where('purpose', 'NORMALIZE')->count();
        $failedChat = (clone $query)->where('purpose', 'CHAT')->where('status', 'FAILED')->count();
        $failedNormalize = (clone $query)->where('purpose', 'NORMALIZE')->where('status', 'FAILED')->count();
        $normalizeFailedSchema = (clone $query)
            ->where('purpose', 'NORMALIZE')
            ->where('status', 'FAILED')
            ->where('error_code', 'AI_BAD_JSON')
            ->count();
        $normalizeFailedProvider = (clone $query)
            ->where('purpose', 'NORMALIZE')
            ->where('status', 'FAILED')
            ->where(function ($q) {
                $q->where('error_code', '!=', 'AI_BAD_JSON')
                    ->orWhereNull('error_code');
            })
            ->count();
        $preflightFailedMissingFields = ConversationEvent::query()
            ->where('client_id', $clientId)
            ->where('type', 'appraisal.preflight.failed')
            ->when($range->from !== null, fn ($q) => $q->whereBetween('created_at', [$range->from, $range->to]))
            ->count();

        $avgLatencyMs = (clone $query)
            ->whereNotNull('completed_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (completed_at - created_at)) * 1000) as avg_latency_ms')
            ->value('avg_latency_ms');

        return response()->json([
            'range' => $range->label,
            'from' => $range->fromIso(),
            'to' => $range->toIso(),
            'client' => [
                'id' => $currentClient->client?->id,
                'name' => $currentClient->client?->name,
            ],
            'total_requests' => (int) $totalRequests,
            'chat_requests' => (int) $chatRequests,
            'normalize_requests' => (int) $normalizeRequests,
            'failed_requests' => (int) $failedRequests,
            'failed_chat' => (int) $failedChat,
            'failed_normalize' => (int) $failedNormalize,
            'normalize_failed_schema' => (int) $normalizeFailedSchema,
            'normalize_failed_provider' => (int) $normalizeFailedProvider,
            'preflight_failed_missing_fields' => (int) $preflightFailedMissingFields,
            'avg_latency_ms' => $avgLatencyMs !== null ? (int) round((float) $avgLatencyMs) : null,
            'estimated_cost_minor' => $estimatedCostMinor,
        ]);
    }

    private function resolveRange(string $rangeLabel): DashboardRange
    {
        try {
            return DashboardRange::parse($rangeLabel, false);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'range' => ['The selected range is invalid.'],
            ]);
        }
    }
}
