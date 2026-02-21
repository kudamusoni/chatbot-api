<?php

namespace App\Models;

use App\Enums\CatalogImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogImport extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'client_id',
        'created_by',
        'status',
        'attempt',
        'file_path',
        'file_hash',
        'mapping',
        'validated_header',
        'totals',
        'errors_count',
        'errors_sample',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogImportStatus::class,
            'mapping' => 'array',
            'validated_header' => 'array',
            'totals' => 'array',
            'errors_sample' => 'array',
            'attempt' => 'integer',
            'errors_count' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
