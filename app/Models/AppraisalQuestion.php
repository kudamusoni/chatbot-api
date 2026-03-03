<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppraisalQuestion extends Model
{
    use HasUuids;

    public const TYPES = ['text', 'number', 'select', 'yes_no'];
    public const ALLOWED_KEYS = ['maker', 'model', 'item_type', 'edition', 'age', 'condition', 'colour', 'size', 'material'];
    public const MANDATORY_KEYS = ['maker', 'model', 'item_type', 'edition', 'age', 'condition', 'colour'];

    /**
     * @var array<int, array{key:string,label:string,help_text:?string,input_type:string,required:bool,order_index:int,is_active:bool,options:?array<int,string>}>
     */
    public const DEFAULT_QUESTIONS = [
        [
            'key' => 'maker',
            'label' => 'Who made it?',
            'help_text' => 'Artist, brand, or manufacturer.',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 1,
            'is_active' => true,
            'options' => null,
        ],
        [
            'key' => 'model',
            'label' => 'What is the model?',
            'help_text' => 'Model name or number, if known.',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 2,
            'is_active' => true,
            'options' => null,
        ],
        [
            'key' => 'item_type',
            'label' => 'What type of item is this?',
            'help_text' => 'e.g. watch, chair, painting, vase.',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 3,
            'is_active' => true,
            'options' => null,
        ],
        [
            'key' => 'edition',
            'label' => 'Is there an edition or series?',
            'help_text' => 'e.g. limited edition, numbered release.',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 4,
            'is_active' => true,
            'options' => null,
        ],
        [
            'key' => 'age',
            'label' => 'How old is it?',
            'help_text' => 'Approximate year or period.',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 5,
            'is_active' => true,
            'options' => null,
        ],
        [
            'key' => 'condition',
            'label' => 'What condition is it in?',
            'help_text' => 'Note any damage, repairs, or wear.',
            'input_type' => 'select',
            'required' => true,
            'order_index' => 6,
            'is_active' => true,
            'options' => ['excellent', 'very_good', 'good', 'fair', 'poor'],
        ],
        [
            'key' => 'colour',
            'label' => 'What colour is it?',
            'help_text' => 'Primary colour(s).',
            'input_type' => 'text',
            'required' => true,
            'order_index' => 7,
            'is_active' => true,
            'options' => null,
        ],
    ];

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

    public static function ensureDefaultsForClient(string $clientId): void
    {
        foreach (self::DEFAULT_QUESTIONS as $questionData) {
            self::query()->firstOrCreate(
                [
                    'client_id' => $clientId,
                    'key' => $questionData['key'],
                ],
                array_merge($questionData, ['client_id' => $clientId])
            );
        }
    }

    public static function isMandatoryKey(string $key): bool
    {
        return in_array($key, self::MANDATORY_KEYS, true);
    }
}
