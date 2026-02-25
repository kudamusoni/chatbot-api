<?php

namespace App\Http\Requests\App;

use App\Enums\CatalogMappingField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartCatalogImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::mappingRules();
    }

    public static function mappingRules(): array
    {
        $allowed = implode(',', CatalogMappingField::allKeys());
        return [
            'mapping' => ["required", "array:{$allowed}"],
            'mapping.title' => ['required', 'string'],
            'mapping.price' => ['sometimes', 'string'],
            'mapping.currency' => ['sometimes', 'string'],
            'mapping.source' => ['sometimes', 'string'],
            'mapping.description' => ['sometimes', 'string'],
            'mapping.sold_at' => ['sometimes', 'string'],
            'mapping.low_estimate' => ['sometimes', 'string'],
            'mapping.high_estimate' => ['sometimes', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mapping = $this->input('mapping', []);
            $hasPrice = is_array($mapping) && isset($mapping['price']) && trim((string) $mapping['price']) !== '';
            $hasLow = is_array($mapping) && isset($mapping['low_estimate']) && trim((string) $mapping['low_estimate']) !== '';
            $hasHigh = is_array($mapping) && isset($mapping['high_estimate']) && trim((string) $mapping['high_estimate']) !== '';

            if (!$hasPrice && !($hasLow && $hasHigh)) {
                $message = 'Provide mapping.price or both mapping.low_estimate and mapping.high_estimate.';
                $validator->errors()->add('mapping.price', $message);
                $validator->errors()->add('mapping.low_estimate', $message);
                $validator->errors()->add('mapping.high_estimate', $message);
            }
        });
    }
}
