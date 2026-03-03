<?php

namespace App\Http\Requests\Widget;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ValuationContactCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action_id' => ['required', 'uuid'],
            'email' => ['required', 'email', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? mb_strtolower(trim((string) $this->input('email'))) : $this->input('email'),
            'name' => is_string($this->input('name')) ? trim((string) $this->input('name')) : $this->input('name'),
            'phone' => is_string($this->input('phone')) ? trim((string) $this->input('phone')) : $this->input('phone'),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'reason_code' => 'INVALID_CONTACT_EMAIL',
            'errors' => $validator->errors(),
        ], 422));
    }
}
