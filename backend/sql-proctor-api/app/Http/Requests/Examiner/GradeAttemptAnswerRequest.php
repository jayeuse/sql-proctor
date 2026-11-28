<?php

namespace App\Http\Requests\Examiner;

use Illuminate\Foundation\Http\FormRequest;

class GradeAttemptAnswerRequest extends FormRequest
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
            'manual_score' => ['required', 'numeric', 'min:0', 'max:1000'],
            'rubric_scores' => ['sometimes', 'nullable', 'array'],
            'rubric_scores.*.id' => ['required_with:rubric_scores', 'string', 'max:64'],
            'rubric_scores.*.points' => ['required_with:rubric_scores', 'numeric', 'min:0', 'max:1000'],
            'examiner_feedback' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
