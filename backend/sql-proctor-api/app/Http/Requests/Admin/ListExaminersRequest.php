<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesPagination;
use Illuminate\Foundation\Http\FormRequest;

class ListExaminersRequest extends FormRequest
{
    use ValidatesPagination;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_active') && is_string($this->input('is_active'))) {
            $normalized = match (strtolower($this->input('is_active'))) {
                'true', '1' => true,
                'false', '0' => false,
                default => $this->input('is_active'),
            };
            $this->merge(['is_active' => $normalized]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'username' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
    }
}
