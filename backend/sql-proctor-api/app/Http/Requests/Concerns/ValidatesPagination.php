<?php

namespace App\Http\Requests\Concerns;

/**
 * Shared offset pagination validation and parameter resolution for FormRequests.
 */
trait ValidatesPagination
{
    protected function defaultPerPage(): int
    {
        return (int) config('pagination.default_per_page', 50);
    }

    protected function maxPerPage(): int
    {
        return (int) config('pagination.max_per_page', 200);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function paginationRules(): array
    {
        return [
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.$this->maxPerPage()],
        ];
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }

    public function perPage(): int
    {
        return min(
            (int) $this->validated('per_page', $this->defaultPerPage()),
            $this->maxPerPage(),
        );
    }
}
