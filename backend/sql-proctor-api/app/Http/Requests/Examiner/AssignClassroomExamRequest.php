<?php

namespace App\Http\Requests\Examiner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignClassroomExamRequest extends FormRequest
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
            'classroom_id' => ['required', 'uuid', Rule::exists('classrooms', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->input('available_from');
            $until = $this->input('available_until');

            if ($from !== null && $until !== null && strtotime((string) $until) <= strtotime((string) $from)) {
                $validator->errors()->add('available_until', 'The available_until timestamp must be after available_from.');
            }
        });
    }
}
