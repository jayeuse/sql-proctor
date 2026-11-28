<?php

namespace App\Http\Requests\Examiner;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDatasetRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'schema_sql' => ['sometimes', 'required', 'string', 'max:100000'],
            'seed_sql' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'is_shared' => ['sometimes', 'boolean'],
        ];
    }
}
