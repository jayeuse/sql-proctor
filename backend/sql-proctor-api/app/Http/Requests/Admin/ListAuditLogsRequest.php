<?php

namespace App\Http\Requests\Admin;

use App\Enums\ActorType;
use App\Http\Requests\Concerns\ValidatesPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAuditLogsRequest extends FormRequest
{
    use ValidatesPagination;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'actor_type' => ['sometimes', 'nullable', 'string', Rule::enum(ActorType::class)],
            'actor_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'event_type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'target_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'target_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => array_values(array_filter([
                'sometimes',
                'nullable',
                'date',
                $this->filled('from') ? 'after_or_equal:from' : null,
            ])),
        ]);
    }
}
