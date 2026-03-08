<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDomainsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $origins = $this->input('allowed_origins');

        if ($origins === null) {
            $origins = $this->input('allowedOrigins');
        }

        $this->merge([
            // Treat omitted payload as "clear domains" and support camelCase clients.
            'allowed_origins' => $origins ?? [],
        ]);
    }

    public function rules(): array
    {
        return [
            'allowed_origins' => ['required', 'array', 'max:50'],
            'allowed_origins.*' => ['required', 'string', 'max:255'],
        ];
    }
}
