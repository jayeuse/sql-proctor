<?php

namespace App\Services\SqlExecution;

readonly class ExecutionResult
{
    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<int, mixed>>  $rawRows
     */
    public function __construct(
        public string $status,
        public array $columns = [],
        public array $rows = [],
        public array $rawRows = [],
        public ?int $rowCount = null,
        public bool $truncated = false,
        public int $executionMs = 0,
        public ?string $errorMessage = null,
        public int $affectedRows = 0,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    /**
     * @return array<string, mixed>
     */
    public function toStudentArray(): array
    {
        return [
            'status' => $this->status,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'row_count' => $this->rowCount ?? count($this->rows),
            'truncated' => $this->truncated,
            'execution_ms' => $this->executionMs,
            'error' => $this->errorMessage,
        ];
    }
}
