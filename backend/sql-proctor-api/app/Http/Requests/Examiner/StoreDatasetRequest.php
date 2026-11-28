<?php

namespace App\Http\Requests\Examiner;

use Illuminate\Foundation\Http\FormRequest;

class StoreDatasetRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'schema_sql' => ['required', 'string', 'max:100000'],
            'seed_sql' => ['nullable', 'string', 'max:200000'],
            'is_shared' => ['sometimes', 'boolean'],
        ];
    }
}
