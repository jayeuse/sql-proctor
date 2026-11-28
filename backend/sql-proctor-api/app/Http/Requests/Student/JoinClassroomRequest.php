<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class JoinClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $updates = [];

        if ($this->has('display_name') && is_string($this->input('display_name'))) {
            $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $this->input('display_name')) ?? '';
            $collapsed = trim((string) preg_replace('/\s+/u', ' ', $cleaned));
            $updates['display_name'] = $collapsed;
        }

        if ($this->has('class_code') && is_string($this->input('class_code'))) {
            $updates['class_code'] = Str::upper(trim($this->input('class_code')));
        }

        if ($updates !== []) {
            $this->merge($updates);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'display_name' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[\p{L}\p{N} ._-]+$/u',
            ],
            'class_code' => [
                'required',
                'string',
                'regex:/^[A-Z0-9]{6,8}$/',
            ],
        ];
    }
}
