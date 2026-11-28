<?php

namespace App\Http\Requests\Examiner;

use Illuminate\Foundation\Http\FormRequest;

class StoreClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isExaminer() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge([
                'name' => trim((string) preg_replace('/\s+/u', ' ', $this->input('name'))),
            ]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'is_open' => ['sometimes', 'boolean'],
            'code_expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
