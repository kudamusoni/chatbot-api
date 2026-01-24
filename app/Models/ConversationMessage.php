<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    use HasUuids;

    /**
     * Immutable: no updated_at column.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_id',
        'client_id',
        'event_id',
        'role',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'created_at' => 'datetime',
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
     * Scope to filter by role.
     */
    public function scopeByRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    /**
     * Get the conversation this message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the client this message belongs to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the event that created this message.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ConversationEvent::class, 'event_id');
    }
}
