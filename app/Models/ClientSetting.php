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
        'colors',
        'prompt_settings',
        'business_details',
        'urls',
        'widget_enabled',
        'allowed_origins',
        'widget_security_version',
    ];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'prompt_settings' => 'array',
            'business_details' => 'array',
            'urls' => 'array',
            'widget_enabled' => 'boolean',
            'allowed_origins' => 'array',
            'widget_security_version' => 'integer',
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
        $settings = self::query()->forClient($clientId)->first();
        if ($settings) {
            return $settings;
        }

        return self::create([
            'client_id' => $clientId,
            'prompt_settings' => [],
            'allowed_origins' => [],
            'widget_security_version' => 1,
        ]);
    }
}
