<?php

namespace App\Http\Requests\Examiner;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClassroomExamRequest extends FormRequest
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
            'is_active' => ['sometimes', 'boolean'],
            'available_from' => ['sometimes', 'nullable', 'date'],
            'available_until' => ['sometimes', 'nullable', 'date'],
            'release_results' => ['sometimes', 'boolean'],
        ];
    }
}
