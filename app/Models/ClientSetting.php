<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientSetting extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'client_id',
        'bot_name',
        'brand_color',
        'accent_color',
        'logo_url',
        'prompt_settings',
        'business_details',
        'widget_enabled',
        'allowed_origins',
        'widget_security_version',
        'ai_enabled',
        'ai_normalization_enabled',
    ];

    protected function casts(): array
    {
        return [
            'prompt_settings' => 'array',
            'business_details' => 'array',
            'widget_enabled' => 'boolean',
            'allowed_origins' => 'array',
            'widget_security_version' => 'integer',
            'ai_enabled' => 'boolean',
            'ai_normalization_enabled' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeForClient($query, string $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public static function forClientOrCreate(string $clientId): self
    {
        return self::query()->firstOrCreate(
            ['client_id' => $clientId],
            [
                'prompt_settings' => [],
                'allowed_origins' => [],
                'widget_security_version' => 1,
                'ai_enabled' => false,
                'ai_normalization_enabled' => false,
            ]
        );
    }
}
