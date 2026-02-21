<?php

namespace App\Http\Requests\App;

use App\Enums\CatalogMappingField;
use Illuminate\Foundation\Http\FormRequest;

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
        $rules = [
            'mapping' => ["required", "array:{$allowed}"],
        ];

        foreach (CatalogMappingField::requiredKeys() as $key) {
            $rules["mapping.{$key}"] = ['required', 'string'];
        }

        foreach (CatalogMappingField::optionalKeys() as $key) {
            $rules["mapping.{$key}"] = ['sometimes', 'string'];
        }

        return $rules;
    }
}
