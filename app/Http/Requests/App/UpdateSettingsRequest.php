<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_name' => ['nullable', 'string', 'max:120'],

            // Flat canonical payload support.
            'bot_name' => ['nullable', 'string', 'max:80'],
            'brand_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'accent_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'prompt_settings' => ['nullable', 'array'],
            'ai_enabled' => ['nullable', 'boolean'],
            'ai_normalization_enabled' => ['nullable', 'boolean'],
            'allowed_origins' => ['nullable', 'array', 'max:50'],
            'allowed_origins.*' => ['nullable', 'string', 'max:255'],

            // Flat additive prompt aliases.
            'fallback_message' => ['nullable', 'string', 'max:2000'],
            'preset_questions' => ['nullable', 'array'],
            'preset_questions.*' => ['nullable', 'string', 'max:160'],
        ];
    }
}
