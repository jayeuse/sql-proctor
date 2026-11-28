<?php

namespace App\Services\SqlExecution;

use App\Enums\QuestionType;
use PDO;

class SqlExecutionService
{
    public function __construct(
        private readonly SqlGuard $guard,
        private readonly SandboxManager $sandboxManager,
    ) {}

    public function execute(
        string $schemaSql,
        ?string $seedSql,
        string $sql,
        QuestionType|string $questionType,
        ?string $followUpSql = null,
    ): ExecutionResult {
        $type = $questionType instanceof QuestionType ? $questionType : QuestionType::from($questionType);

        $checked = $this->guard->validate($sql, $type);
        if (! $checked['ok']) {
            return new ExecutionResult(
                status: 'policy_blocked',
                errorMessage: $checked['reason'],
            );
        }

        $followUpStatements = [];
        if ($followUpSql !== null && trim($followUpSql) !== '') {
            $followCheck = $this->guard->validate($followUpSql, QuestionType::SqlDml);
            if (! $followCheck['ok']) {
                return new ExecutionResult(
                    status: 'policy_blocked',
                    errorMessage: $followCheck['reason'],
                );
            }
            $followUpStatements = $followCheck['statements'];
        }

        $maxRows = (int) config('sql_proctor.sandbox.max_rows', 500);
        $maxCellBytes = (int) config('sql_proctor.sandbox.max_cell_bytes', 4096);
        $maxResultBytes = (int) config('sql_proctor.sandbox.max_result_bytes', 1048576);

        $outcome = $this->sandboxManager->runInSandbox(
            $schemaSql,
            $seedSql,
            $type,
            function (PDO $runnerPdo) use ($checked, $followUpStatements, $maxRows, $maxCellBytes, $maxResultBytes): ExecutionResult {
                $lastColumns = [];
                $lastRows = [];
                $lastRawRows = [];
                $lastTruncated = false;
                $totalAffected = 0;

                $allStatements = array_merge($checked['statements'], $followUpStatements);

                foreach ($allStatements as $statement) {
                    $stmt = $runnerPdo->query($statement);
                    if ($stmt === false) {
                        continue;
                    }

                    try {
                        $columnCount = $stmt->columnCount();

                        if ($columnCount === 0) {
                            $totalAffected += $stmt->rowCount();

                            continue;
                        }

                        $columns = [];
                        $uniqueKeys = [];
                        for ($i = 0; $i < $columnCount; $i++) {
                            $meta = $stmt->getColumnMeta($i);
                            $name = is_array($meta) && isset($meta['name']) ? (string) $meta['name'] : "col_{$i}";
                            $columns[] = $name;

                            $key = $name;
                            $suffix = 2;
                            while (in_array($key, $uniqueKeys, true)) {
                                $key = "{$name}_{$suffix}";
                                $suffix++;
                            }
                            $uniqueKeys[] = $key;
                        }

                        $rows = [];
                        $rawRows = [];
                        $truncated = false;
                        $bytesRead = 0;

                        while (($tuple = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                            if (count($rows) >= $maxRows) {
                                $truncated = true;
                                break;
                            }

                            $assoc = [];
                            $raw = [];
                            $rowBytes = 0;

                            foreach ($tuple as $index => $cell) {
                                $normalized = $this->normalizeCell($cell, $maxCellBytes);
                                $rowBytes += $normalized === null ? 4 : strlen((string) $normalized);
                                $raw[] = $normalized;
                                $assoc[$uniqueKeys[$index]] = $normalized;
                            }

                            if ($bytesRead + $rowBytes > $maxResultBytes && count($rows) > 0) {
                                $truncated = true;
                                break;
                            }

                            $bytesRead += $rowBytes;
                            $rawRows[] = $raw;
                            $rows[] = $assoc;
                        }

                        $lastColumns = $columns;
                        $lastRows = $rows;
                        $lastRawRows = $rawRows;
                        $lastTruncated = $truncated;
                    } finally {
                        $stmt->closeCursor();
                    }
                }

                return new ExecutionResult(
                    status: 'succeeded',
                    columns: $lastColumns,
                    rows: $lastRows,
                    rawRows: $lastRawRows,
                    rowCount: count($lastRows),
                    truncated: $lastTruncated,
                    affectedRows: $totalAffected,
                );
            },
        );

        if ($outcome['status'] !== 'succeeded') {
            return new ExecutionResult(
                status: $outcome['status'],
                executionMs: $outcome['executionMs'],
                errorMessage: $outcome['errorMessage'] ?? 'SQL execution failed.',
            );
        }

        /** @var ExecutionResult $result */
        $result = $outcome['result'];

        return new ExecutionResult(
            status: 'succeeded',
            columns: $result->columns,
            rows: $result->rows,
            rawRows: $result->rawRows,
            rowCount: $result->rowCount,
            truncated: $result->truncated,
            executionMs: $outcome['executionMs'],
            affectedRows: $result->affectedRows,
        );
    }

    private function normalizeCell(mixed $cell, int $maxCellBytes): mixed
    {
        if ($cell === null || is_int($cell) || is_float($cell) || is_bool($cell)) {
            return $cell;
        }

        $str = (string) $cell;
        if (strlen($str) > $maxCellBytes) {
            return substr($str, 0, max(0, $maxCellBytes - 3)).'...';
        }

        return $str;
    }
}
