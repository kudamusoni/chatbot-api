<?php

namespace App\Http\Requests\App;

use App\Models\AppraisalQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppraisalQuestionRequest extends FormRequest
{
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
            'key' => ['sometimes', 'string', 'regex:/^[a-z0-9_]{2,64}$/'],
            'question' => ['sometimes', 'string', 'max:500'],
            'type' => ['sometimes', 'string', Rule::in(AppraisalQuestion::TYPES)],
            'options' => ['nullable', 'array', 'min:1', 'max:100'],
            'options.*' => ['required', 'string', 'max:120'],
            'is_required' => ['sometimes', 'boolean'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
