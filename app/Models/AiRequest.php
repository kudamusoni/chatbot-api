<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_id',
        'conversation_id',
        'event_id',
        'purpose',
        'provider',
        'model',
        'prompt_version',
        'policy_version',
        'prompt_hash',
        'input_tokens',
        'output_tokens',
        'cost_estimate_minor',
        'status',
        'error_code',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_estimate_minor' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ConversationEvent::class, 'event_id');
    }
}

