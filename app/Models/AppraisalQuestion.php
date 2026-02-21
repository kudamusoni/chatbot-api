<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppraisalQuestion extends Model
{
    use HasUuids;

    public const TYPES = ['text', 'number', 'select', 'yes_no'];

    protected $fillable = [
        'client_id',
        'key',
        'label',
        'help_text',
        'input_type',
        'required',
        'order_index',
        'is_active',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'is_active' => 'boolean',
            'options' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
