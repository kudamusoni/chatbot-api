<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDomainsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allowed_origins' => ['required', 'array', 'max:50'],
            'allowed_origins.*' => ['required', 'string', 'max:255'],
        ];
    }
}

