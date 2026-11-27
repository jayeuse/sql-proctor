<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base FormRequest for endpoints that accept only standard pagination parameters.
 */
class PaginatedRequest extends FormRequest
{
    use ValidatesPagination;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return $this->paginationRules();
    }
}
