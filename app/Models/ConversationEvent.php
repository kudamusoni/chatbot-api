<?php

namespace App\Models;

use App\Enums\ConversationEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ConversationEvent extends Model
{
    /**
     * Use BIGINT auto-increment, not UUID.
     */
    public $incrementing = true;

    /**
     * Immutable: no updated_at column.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_id',
        'client_id',
        'type',
        'payload',
        'correlation_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConversationEventType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Prevent updates to existing events (immutable).
     */
    public static function boot(): void
    {
        parent::boot();

        static::updating(function (self $event) {
            throw new LogicException('ConversationEvent records are immutable and cannot be updated.');
        });

        static::deleting(function (self $event) {
            throw new LogicException('ConversationEvent records are immutable and cannot be deleted.');
        });
    }

    /**
     * Scope to filter by client.
     */
    public function scopeForClient(Builder $query, string $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope to get events after a specific ID (for SSE replay).
     */
    public function scopeAfter(Builder $query, int $afterId): Builder
    {
        return $query->where('id', '>', $afterId);
    }

    /**
     * Get the conversation this event belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the client this event belongs to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the message projection for this event (if any).
     */
    public function message(): HasOne
    {
        return $this->hasOne(ConversationMessage::class, 'event_id');
    }

    /**
     * Check if this event type produces a message.
     */
    public function producesMessage(): bool
    {
        return $this->type->producesMessage();
    }

    /**
     * Get the message role if this event produces a message.
     */
    public function messageRole(): ?string
    {
        return $this->type->messageRole();
    }
}
