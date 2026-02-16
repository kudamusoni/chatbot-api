<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'conversation_id',
        'client_id',
        'request_event_id',
        'name',
        'email',
        'phone_raw',
        'phone_normalized',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'request_event_id' => 'integer',
        ];
    }

    /**
     * Scope to filter by client.
     */
    public function scopeForClient(Builder $query, string $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Get the conversation this request belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the client this request belongs to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the source event for this request.
     */
    public function requestEvent(): BelongsTo
    {
        return $this->belongsTo(ConversationEvent::class, 'request_event_id');
    }
}
