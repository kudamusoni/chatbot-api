<?php

namespace App\Http\Controllers\App;

use App\Enums\ProductSource;
use App\Http\Controllers\Controller;
use App\Models\ProductCatalog;
use App\Support\CurrentClient;
use App\Support\DashboardListDefaults;
use App\Support\DashboardRange;
use App\Support\ProductCatalogPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        $perPage = DashboardListDefaults::perPage($request);

        $query = ProductCatalog::query()
            ->forClient((string) $currentClient->id());

        if ($request->filled('q')) {
            $query->search((string) $request->query('q'));
        }

        if ($request->filled('source')) {
            $source = strtolower(trim((string) $request->query('source')));
            $allowedSources = array_map(
                static fn (ProductSource $case): string => $case->value,
                ProductSource::cases()
            );

            if (!in_array($source, $allowedSources, true)) {
                throw ValidationException::withMessages([
                    'source' => ['The selected source is invalid.'],
                ]);
            }

            $query->where('source', $source);
        }

        if ($request->filled('currency')) {
            $currency = strtoupper(trim((string) $request->query('currency')));
            $query->where('currency', $currency);
        }

        if ($request->filled('range')) {
            try {
                $parsed = DashboardRange::parse((string) $request->query('range'));
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'range' => ['The selected range is invalid.'],
                ]);
            }

            if ($parsed->from !== null) {
                $query->whereBetween('created_at', [$parsed->from, $parsed->to]);
            }
        }

        DashboardListDefaults::applyDefaultSort($query, 'products');

        $products = $query->paginate($perPage)
            ->through(fn (ProductCatalog $product) => ProductCatalogPresenter::present($product));

        return response()->json(DashboardListDefaults::withDefaultSortMeta($products, 'products'));
    }

    public function show(string $id): JsonResponse
    {
        /** @var CurrentClient $currentClient */
        $currentClient = app(CurrentClient::class);

        $product = ProductCatalog::query()
            ->forClient((string) $currentClient->id())
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'data' => ProductCatalogPresenter::present($product),
        ]);
    }
}
