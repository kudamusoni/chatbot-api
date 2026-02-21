<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\CatalogImport;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Valuation;
use App\Support\CurrentClient;
use App\Support\DashboardRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AnalyticsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $rangeLabel = (string) $request->query('range', '7d');
        $range = $this->resolveRange($rangeLabel, false);

        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $clientId = (string) $currentClient->id();

        $conversations = $this->countWithinRange(
            Conversation::query()->where('client_id', $clientId)->whereNotNull('last_activity_at'),
            'last_activity_at',
            $range
        );

        $valuations = $this->countWithinRange(
            Valuation::query()->where('client_id', $clientId),
            'created_at',
            $range
        );

        $leads = $this->countWithinRange(
            Lead::query()->where('client_id', $clientId),
            'created_at',
            $range
        );

        $catalogImports = $this->countWithinRange(
            CatalogImport::query()->where('client_id', $clientId),
            'created_at',
            $range
        );

        return response()->json([
            'range' => $range->label,
            'client' => [
                'id' => $currentClient->client?->id,
                'name' => $currentClient->client?->name,
            ],
            'from' => $range->fromIso(),
            'to' => $range->toIso(),
            'conversations' => $conversations,
            'valuations' => $valuations,
            'leads' => $leads,
            'catalog_imports' => $catalogImports,
        ]);
    }

    public function timeseries(Request $request): JsonResponse
    {
        $rangeLabel = (string) $request->query('range', '30d');
        $range = $this->resolveRange($rangeLabel, true);

        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $clientId = (string) $currentClient->id();

        $dayKeys = $range->dayKeys();

        $conversationByDay = $this->countsByDay(
            Conversation::query()->where('client_id', $clientId)->whereNotNull('last_activity_at'),
            'last_activity_at',
            $range
        );
        $valuationByDay = $this->countsByDay(
            Valuation::query()->where('client_id', $clientId),
            'created_at',
            $range
        );
        $leadByDay = $this->countsByDay(
            Lead::query()->where('client_id', $clientId),
            'created_at',
            $range
        );
        $importByDay = $this->countsByDay(
            CatalogImport::query()->where('client_id', $clientId),
            'created_at',
            $range
        );

        $data = [];
        foreach ($dayKeys as $day) {
            $data[] = [
                'date' => $day,
                'conversations' => (int) ($conversationByDay[$day] ?? 0),
                'valuations' => (int) ($valuationByDay[$day] ?? 0),
                'leads' => (int) ($leadByDay[$day] ?? 0),
                'catalog_imports' => (int) ($importByDay[$day] ?? 0),
            ];
        }

        return response()->json([
            'range' => $range->label,
            'client' => [
                'id' => $currentClient->client?->id,
                'name' => $currentClient->client?->name,
            ],
            'from' => $range->fromIso(),
            'to' => $range->toIso(),
            'data' => $data,
        ]);
    }

    private function resolveRange(string $rangeLabel, bool $timeseriesAllCapped): DashboardRange
    {
        try {
            return DashboardRange::parse($rangeLabel, $timeseriesAllCapped);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'range' => ['The selected range is invalid.'],
            ]);
        }
    }

    private function countWithinRange($query, string $column, DashboardRange $range): int
    {
        if ($range->from !== null) {
            $query->whereBetween($column, [$range->from, $range->to]);
        }

        return (int) $query->count();
    }

    /**
     * @return array<string, int>
     */
    private function countsByDay($query, string $column, DashboardRange $range): array
    {
        if ($range->from !== null) {
            $query->whereBetween($column, [$range->from, $range->to]);
        }

        return $query
            ->selectRaw("DATE({$column}) as day, COUNT(*) as aggregate")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->day => (int) $row->aggregate])
            ->all();
    }
}
