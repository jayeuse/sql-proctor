<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class SaveAnswerRequest extends FormRequest
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
            'submitted_sql' => ['sometimes', 'nullable', 'string', 'max:32768'],
            'submitted_text' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'selected_option_ids' => ['sometimes', 'nullable', 'array'],
            'selected_option_ids.*' => ['uuid'],
            'submit_answer' => ['sometimes', 'boolean'],
        ];
    }
}
