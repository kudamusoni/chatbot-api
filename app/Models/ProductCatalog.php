<?php

namespace App\Models;

use App\Enums\ProductSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCatalog extends Model
{
    use HasUuids;

    protected $table = 'product_catalog';

    protected $fillable = [
        'client_id',
        'title',
        'description',
        'source',
        'price',
        'currency',
        'sold_at',
        'normalized_text',
    ];

    protected function casts(): array
    {
        return [
            'source' => ProductSource::class,
            'price' => 'integer',
            'sold_at' => 'datetime',
        ];
    }

    /**
     * Boot the model and auto-compute normalized_text on save.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $model) {
            $model->normalized_text = $model->computeNormalizedText();
        });
    }

    /**
     * Compute normalized text for search indexing.
     * Combines title and description, lowercased and cleaned.
     */
    public function computeNormalizedText(): string
    {
        $parts = array_filter([
            $this->title,
            $this->description,
        ]);

        $text = implode(' ', $parts);

        // Lowercase and remove extra whitespace
        return strtolower(preg_replace('/\s+/', ' ', trim($text)));
    }

    /**
     * Scope to filter by client (tenant isolation).
     */
    public function scopeForClient(Builder $query, string $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope to filter by source type.
     */
    public function scopeWithSource(Builder $query, ProductSource $source): Builder
    {
        return $query->where('source', $source);
    }

    /**
     * Scope to filter by currency.
     */
    public function scopeWithCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope to filter sold items.
     */
    public function scopeSold(Builder $query): Builder
    {
        return $query->where('source', ProductSource::SOLD);
    }

    /**
     * Scope to search by normalized text using ILIKE.
     * The pg_trgm index makes this fast even with leading wildcards.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = strtolower(trim($term));

        if ($term === '') {
            return $query;
        }

        return $query->whereRaw('normalized_text ILIKE ?', ["%{$term}%"]);
    }

    /**
     * Scope to search by multiple terms (OR matching).
     */
    public function scopeSearchTerms(Builder $query, array $terms): Builder
    {
        $terms = array_filter(array_map(fn ($t) => strtolower(trim($t)), $terms));

        if (empty($terms)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($terms) {
            foreach ($terms as $term) {
                $q->orWhereRaw('normalized_text ILIKE ?', ["%{$term}%"]);
            }
        });
    }

    /**
     * Get the client that owns this product.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the price in major currency units (e.g., pounds/dollars).
     */
    public function getPriceInMajorUnitsAttribute(): float
    {
        return $this->price / 100;
    }
}
