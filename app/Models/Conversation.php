<?php

namespace App\Models;

use App\Enums\ConversationState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'client_id',
        'session_token_hash',
        'state',
        'context',
        'appraisal_answers',
        'appraisal_current_key',
        'appraisal_snapshot',
        'lead_answers',
        'lead_current_key',
        'lead_identity_candidate',
        'last_event_id',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => ConversationState::class,
            'context' => 'array',
            'appraisal_answers' => 'array',
            'appraisal_snapshot' => 'array',
            'lead_answers' => 'array',
            'lead_identity_candidate' => 'array',
            'last_event_id' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Generate a new session token and return it (raw).
     * The hash is stored, but the raw token is returned for the client.
     */
    public static function generateSessionToken(): string
    {
        return Str::random(64);
    }

    /**
     * Hash a session token for storage.
     */
    public static function hashSessionToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Create a new conversation with a generated session token.
     * Returns [Conversation, rawToken] tuple.
     *
     * @return array{0: Conversation, 1: string}
     */
    public static function createWithToken(string $clientId, array $attributes = []): array
    {
        $rawToken = self::generateSessionToken();
        $hash = self::hashSessionToken($rawToken);

        // Defaults come first, then provided attributes can override (except client_id and token)
        $conversation = self::create(array_merge([
            'state' => ConversationState::CHAT,
        ], $attributes, [
            'client_id' => $clientId,
            'session_token_hash' => $hash,
        ]));

        return [$conversation, $rawToken];
    }

    /**
     * Find a conversation by its raw session token.
     *
     * @deprecated Use findByTokenForClient() for proper tenant isolation.
     *             This method exists only for edge cases where client context is unavailable.
     */
    public static function findByToken(string $token): ?self
    {
        trigger_error(
            'Conversation::findByToken() is deprecated. Use findByTokenForClient() for tenant isolation.',
            E_USER_DEPRECATED
        );

        $hash = self::hashSessionToken($token);

        return self::where('session_token_hash', $hash)->first();
    }

    /**
     * Find a conversation by its raw session token within a specific client.
     * This is the preferred method for tenant-safe token lookups.
     */
    public static function findByTokenForClient(string $token, string $clientId): ?self
    {
        $hash = self::hashSessionToken($token);

        return self::where('session_token_hash', $hash)
            ->where('client_id', $clientId)
            ->first();
    }

    /**
     * Scope to filter by client.
     */
    public function scopeForClient(Builder $query, string $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope to order by recent activity.
     */
    public function scopeRecentActivity(Builder $query): Builder
    {
        return $query->orderByDesc('last_activity_at');
    }

    /**
     * Get the client that owns this conversation.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get all events for this conversation.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ConversationEvent::class)->orderBy('id');
    }

    /**
     * Get all messages for this conversation (projection).
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('created_at');
    }

    /**
     * Get all valuations for this conversation.
     */
    public function valuations(): HasMany
    {
        return $this->hasMany(Valuation::class);
    }

    /**
     * Get all leads for this conversation.
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Get events after a specific event ID (for SSE replay).
     */
    public function eventsAfter(int $afterId): HasMany
    {
        return $this->events()->where('id', '>', $afterId);
    }
}
