<?php

namespace App\Http\Requests\Examiner;

use App\Enums\AttemptGradingStatus;
use App\Enums\AttemptStatus;
use App\Http\Requests\Concerns\ValidatesPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListExamAttemptsRequest extends FormRequest
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
            'classroom_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::enum(AttemptStatus::class)],
            'grading_status' => ['sometimes', Rule::enum(AttemptGradingStatus::class)],
        ]);
    }
}
