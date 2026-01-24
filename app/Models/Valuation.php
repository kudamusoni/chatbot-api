<?php

namespace App\Models;

use App\Enums\ValuationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Valuation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'conversation_id',
        'client_id',
        'request_event_id',
        'status',
        'snapshot_hash',
        'input_snapshot',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'status' => ValuationStatus::class,
            'request_event_id' => 'integer',
            'input_snapshot' => 'array',
            'result' => 'array',
        ];
    }

    /**
     * Generate a snapshot hash from input data.
     */
    public static function generateSnapshotHash(array $inputSnapshot): string
    {
        // Sort keys for consistent hashing
        ksort($inputSnapshot);

        return hash('sha256', json_encode($inputSnapshot));
    }

    /**
     * Create a valuation with auto-generated snapshot hash.
     */
    public static function createFromSnapshot(
        string $conversationId,
        string $clientId,
        array $inputSnapshot,
        ?int $requestEventId = null
    ): self {
        return self::create([
            'conversation_id' => $conversationId,
            'client_id' => $clientId,
            'request_event_id' => $requestEventId,
            'status' => ValuationStatus::PENDING,
            'snapshot_hash' => self::generateSnapshotHash($inputSnapshot),
            'input_snapshot' => $inputSnapshot,
        ]);
    }

    /**
     * Find an existing valuation by conversation and snapshot.
     */
    public static function findBySnapshot(string $conversationId, array $inputSnapshot): ?self
    {
        $hash = self::generateSnapshotHash($inputSnapshot);

        return self::where('conversation_id', $conversationId)
            ->where('snapshot_hash', $hash)
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
     * Scope to filter by status.
     */
    public function scopeWithStatus(Builder $query, ValuationStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get pending valuations.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ValuationStatus::PENDING);
    }

    /**
     * Get the conversation this valuation belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the client this valuation belongs to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the event that requested this valuation.
     */
    public function requestEvent(): BelongsTo
    {
        return $this->belongsTo(ConversationEvent::class, 'request_event_id');
    }

    /**
     * Check if this valuation is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Mark the valuation as running.
     */
    public function markRunning(): self
    {
        $this->update(['status' => ValuationStatus::RUNNING]);

        return $this;
    }

    /**
     * Mark the valuation as completed with result.
     */
    public function markCompleted(array $result): self
    {
        $this->update([
            'status' => ValuationStatus::COMPLETED,
            'result' => $result,
        ]);

        return $this;
    }

    /**
     * Mark the valuation as failed.
     */
    public function markFailed(?array $errorDetails = null): self
    {
        $this->update([
            'status' => ValuationStatus::FAILED,
            'result' => $errorDetails,
        ]);

        return $this;
    }
}
