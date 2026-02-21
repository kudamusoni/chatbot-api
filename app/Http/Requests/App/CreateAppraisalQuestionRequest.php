<?php

namespace App\Http\Requests\App;

use App\Models\AppraisalQuestion;
use App\Support\CurrentClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAppraisalQuestionRequest extends FormRequest
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
            'key' => [
                'required',
                'string',
                'regex:/^[a-z0-9_]{2,64}$/',
                Rule::unique('appraisal_questions', 'key')->where(function ($query) {
                    return $query->where('client_id', (string) app(CurrentClient::class)->id());
                }),
            ],
            'question' => ['required', 'string', 'max:500'],
            'type' => ['required', 'string', Rule::in(AppraisalQuestion::TYPES)],
            'options' => ['nullable', 'array', 'min:1', 'max:100'],
            'options.*' => ['required', 'string', 'max:120'],
            'is_required' => ['sometimes', 'boolean'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $type = (string) $this->input('type');
            $hasOptions = $this->filled('options');

            if ($type === 'select' && !$hasOptions) {
                $validator->errors()->add('options', 'The options field is required when type is select.');
            }

            if ($type !== 'select' && $hasOptions) {
                $validator->errors()->add('options', 'The options field is only allowed when type is select.');
            }
        });
    }
}
