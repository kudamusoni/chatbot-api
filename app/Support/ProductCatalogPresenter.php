<?php

namespace App\Support;

use App\Models\ProductCatalog;

class ProductCatalogPresenter
{
    public static function present(ProductCatalog $product): array
    {
        $source = $product->source;

        return [
            'id' => $product->id,
            'title' => $product->title,
            'description' => $product->description,
            'source' => is_object($source) ? $source->value : (string) $source,
            'price' => (int) $product->price,
            'low_estimate' => $product->low_estimate !== null ? (int) $product->low_estimate : null,
            'high_estimate' => $product->high_estimate !== null ? (int) $product->high_estimate : null,
            'currency' => (string) $product->currency,
            'sold_at' => $product->sold_at?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'created_at' => $product->created_at?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'updated_at' => $product->updated_at?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
        ];
    }
}
