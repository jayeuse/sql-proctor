<?php

namespace App\Http\Requests\Examiner;

use App\Enums\ExamStatus;
use App\Http\Requests\Concerns\ValidatesPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListExamsRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(ExamStatus::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'examiner_id' => ['sometimes', 'integer', 'min:1'],
        ]);
    }
}
