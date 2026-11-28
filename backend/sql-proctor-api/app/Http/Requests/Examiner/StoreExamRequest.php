<?php

namespace App\Http\Requests\Examiner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'dataset_id' => ['nullable', 'uuid', Rule::exists('sql_datasets', 'id')],
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:480'],
            'passing_score_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'settings' => ['sometimes', 'array'],
            'settings.allow_query_preview' => ['sometimes', 'boolean'],
            'settings.shuffle_questions' => ['sometimes', 'boolean'],
            'settings.show_points' => ['sometimes', 'boolean'],
            'settings.immediate_auto_results' => ['sometimes', 'boolean'],
            'settings.max_query_runs_per_question' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
        ];
    }
}
