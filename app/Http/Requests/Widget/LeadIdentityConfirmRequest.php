<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

class LeadIdentityConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
            'session_token' => ['required', 'string', 'size:64'],
            'action_id' => ['required', 'uuid'],
            'use_existing' => ['required', 'boolean'],
        ];
    }
}
