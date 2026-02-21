<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    private const STATUSES = ['REQUESTED', 'CONTACTED', 'QUALIFIED', 'WON', 'LOST'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
