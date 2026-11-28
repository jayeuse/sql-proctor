<?php

namespace App\Http\Requests\Examiner;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClassroomScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isExaminer() ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code_expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
