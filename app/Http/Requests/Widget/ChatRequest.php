<?php

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Widget endpoints are public
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
            'session_token' => ['required', 'string', 'size:64'],
            'message_id' => ['required', 'uuid'],
            'text' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Trim whitespace from text
        if ($this->has('text')) {
            $this->merge([
                'text' => trim($this->text),
            ]);
        }
    }
}
