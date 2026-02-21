<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Valuation;
use App\Support\CurrentClient;
use App\Support\DashboardListDefaults;
use App\Support\DashboardRange;
use App\Support\ValuationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ValuationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);
        $perPage = DashboardListDefaults::perPage($request);

        $query = Valuation::query()->where('client_id', $currentClient->id());

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('range')) {
            $this->applyRange($query, (string) $request->query('range'), 'created_at');
        }

        DashboardListDefaults::applyDefaultSort($query, 'valuations');

        $items = $query->paginate($perPage)
            ->through(fn (Valuation $valuation) => ValuationPresenter::listItem($valuation));

        $confidenceMin = $request->query('confidence_min');
        if ($confidenceMin !== null && is_numeric($confidenceMin)) {
            $min = (float) $confidenceMin;
            $data = collect($items->items())
                ->filter(fn (array $item) => (float) $item['confidence'] >= $min)
                ->values()
                ->all();
            $items->setCollection(collect($data));
        }

        return response()->json(DashboardListDefaults::withDefaultSortMeta($items, 'valuations'));
    }

    public function show(string $id): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        $valuation = Valuation::query()
            ->where('client_id', $currentClient->id())
            ->where('id', $id)
            ->firstOrFail();

        return response()->json(['data' => ValuationPresenter::detail($valuation)]);
    }

    private function applyRange($query, string $range, string $column): void
    {
        try {
            $parsed = DashboardRange::parse($range);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'range' => ['The selected range is invalid.'],
            ]);
        }

        if ($parsed->from !== null) {
            $query->whereBetween($column, [$parsed->from, $parsed->to]);
        }
    }
}
