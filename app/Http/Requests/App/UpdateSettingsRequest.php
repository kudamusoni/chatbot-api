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
            'client' => ['sometimes', 'array'],
            'client.name' => ['nullable', 'string', 'max:120'],

            'settings' => ['sometimes', 'array'],
            'settings.bot_name' => ['nullable', 'string', 'max:80'],
            'settings.brand_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'settings.accent_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'settings.logo_url' => ['nullable', 'url', 'max:500'],
            'settings.prompt_settings' => ['nullable', 'array'],
        ];
    }
}

