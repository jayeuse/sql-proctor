<?php

namespace App\Http\Requests\Examiner;

use App\Http\Requests\Concerns\ValidatesPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDatasetsRequest extends FormRequest
{
    use ValidatesPagination;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'scope' => ['sometimes', Rule::in(['mine', 'shared', 'all'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
    }
}
