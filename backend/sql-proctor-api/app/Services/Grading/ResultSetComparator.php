<?php

namespace App\Services\Grading;

use App\Services\SqlExecution\SqlSplitter;

class ResultSetComparator
{
    /**
     * Compare reference and submitted query result sets against comparison_config rules.
     *
     * @param  array{columns: array<int, string>, rows: array<int, array<string, mixed>>, rawRows?: array<int, array<int, mixed>>}  $reference
     * @param  array{columns: array<int, string>, rows: array<int, array<string, mixed>>, rawRows?: array<int, array<int, mixed>>}  $submitted
     * @param  array<string, mixed>  $config
     * @return array{matched: bool, reason: ?string}
     */
    public function matches(array $reference, array $submitted, array $config = []): array
    {
        $orderSensitive = (bool) ($config['order_sensitive'] ?? false);
        $columnNameSensitive = (bool) ($config['column_name_sensitive'] ?? false);
        $caseSensitiveValues = (bool) ($config['case_sensitive_values'] ?? true);
        $floatTolerance = (float) ($config['float_tolerance'] ?? config('sql_proctor.grading.default_float_tolerance', 0.001));

        $refCols = array_values($reference['columns']);
        $subCols = array_values($submitted['columns']);

        if (count($refCols) !== count($subCols)) {
            return [
                'matched' => false,
                'reason' => sprintf('Expected %d column(s), got %d.', count($refCols), count($subCols)),
            ];
        }

        if ($columnNameSensitive && $refCols !== $subCols) {
            return [
                'matched' => false,
                'reason' => 'Column names/aliases do not match expected output.',
            ];
        }

        $refRows = $this->extractTuples($reference);
        $subRows = $this->extractTuples($submitted);

        if (count($refRows) !== count($subRows)) {
            return [
                'matched' => false,
                'reason' => sprintf('Expected %d row(s), got %d.', count($refRows), count($subRows)),
            ];
        }

        if ($refRows === []) {
            return ['matched' => true, 'reason' => null];
        }

        if ($orderSensitive) {
            foreach ($refRows as $i => $refRow) {
                if (! $this->rowsMatch($refRow, $subRows[$i], $caseSensitiveValues, $floatTolerance)) {
                    return [
                        'matched' => false,
                        'reason' => 'Row '.($i + 1).' does not match expected result in strict order.',
                    ];
                }
            }

            return ['matched' => true, 'reason' => null];
        }

        // Order-insensitive matching: sort canonically and also tolerate float tolerance edges.
        $sortedRef = $this->sortRows($refRows, $caseSensitiveValues);
        $sortedSub = $this->sortRows($subRows, $caseSensitiveValues);

        $exactAll = true;
        foreach ($sortedRef as $i => $refRow) {
            if (! $this->rowsMatch($refRow, $sortedSub[$i], $caseSensitiveValues, $floatTolerance)) {
                $exactAll = false;
                break;
            }
        }

        if ($exactAll) {
            return ['matched' => true, 'reason' => null];
        }

        // Greedy unmatched search for floating-point values sitting near rounding boundaries.
        $used = [];
        foreach ($refRows as $refRow) {
            $found = false;
            foreach ($subRows as $j => $subRow) {
                if (isset($used[$j])) {
                    continue;
                }
                if ($this->rowsMatch($refRow, $subRow, $caseSensitiveValues, $floatTolerance)) {
                    $used[$j] = true;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return [
                    'matched' => false,
                    'reason' => 'Result rows do not match expected output.',
                ];
            }
        }

        return ['matched' => true, 'reason' => null];
    }

    /**
     * Validate optional static SQL constraints (`required_clauses`, `forbidden_patterns`).
     *
     * @param  array<string, mixed>  $config
     * @return array{passed: bool, reason: ?string}
     */
    public function checkSqlConstraints(string $sql, array $config): array
    {
        $inspected = SqlSplitter::sanitizeForInspection($sql);

        $required = $config['required_clauses'] ?? [];
        if (is_array($required)) {
            foreach ($required as $clause) {
                $clauseStr = trim((string) $clause);
                if ($clauseStr !== '' && stripos($inspected, $clauseStr) === false) {
                    return [
                        'passed' => false,
                        'reason' => "Submission is missing required SQL clause: {$clauseStr}.",
                    ];
                }
            }
        }

        $forbidden = $config['forbidden_patterns'] ?? [];
        if (is_array($forbidden)) {
            foreach ($forbidden as $pattern) {
                $pat = trim((string) $pattern);
                if ($pat === '') {
                    continue;
                }
                if (stripos($inspected, $pat) !== false || stripos($sql, $pat) !== false) {
                    return [
                        'passed' => false,
                        'reason' => 'Submission uses a disallowed SQL pattern.',
                    ];
                }
            }
        }

        return ['passed' => true, 'reason' => null];
    }

    /**
     * @param  array{columns: array<int, string>, rows: array<int, array<string, mixed>>, rawRows?: array<int, array<int, mixed>>}  $resultSet
     * @return array<int, array<int, mixed>>
     */
    private function extractTuples(array $resultSet): array
    {
        if (isset($resultSet['rawRows']) && is_array($resultSet['rawRows'])) {
            return array_values($resultSet['rawRows']);
        }

        return array_map(
            static fn (array $row): array => array_values($row),
            array_values($resultSet['rows']),
        );
    }

    /**
     * @param  array<int, mixed>  $a
     * @param  array<int, mixed>  $b
     */
    private function rowsMatch(array $a, array $b, bool $caseSensitiveValues, float $floatTolerance): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $i => $valA) {
            if (! $this->cellsMatch($valA, $b[$i] ?? null, $caseSensitiveValues, $floatTolerance)) {
                return false;
            }
        }

        return true;
    }

    private function cellsMatch(mixed $a, mixed $b, bool $caseSensitiveValues, float $floatTolerance): bool
    {
        // Strict distinction between SQL NULL and empty string "" or 0.
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        if ($this->isNumericScalar($a) && $this->isNumericScalar($b)) {
            return abs((float) $a - (float) $b) <= $floatTolerance;
        }

        $strA = (string) $a;
        $strB = (string) $b;

        if (! $caseSensitiveValues) {
            return mb_strtolower($strA, 'UTF-8') === mb_strtolower($strB, 'UTF-8');
        }

        return $strA === $strB;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function sortRows(array $rows, bool $caseSensitiveValues): array
    {
        usort($rows, function (array $rowA, array $rowB) use ($caseSensitiveValues): int {
            $count = max(count($rowA), count($rowB));
            for ($i = 0; $i < $count; $i++) {
                $cmp = $this->compareCells($rowA[$i] ?? null, $rowB[$i] ?? null, $caseSensitiveValues);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });

        return $rows;
    }

    private function compareCells(mixed $a, mixed $b, bool $caseSensitiveValues): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return -1;
        }
        if ($b === null) {
            return 1;
        }

        if ($this->isNumericScalar($a) && $this->isNumericScalar($b)) {
            return (float) $a <=> (float) $b;
        }

        $strA = (string) $a;
        $strB = (string) $b;

        if (! $caseSensitiveValues) {
            return strcmp(mb_strtolower($strA, 'UTF-8'), mb_strtolower($strB, 'UTF-8'));
        }

        return strcmp($strA, $strB);
    }

    private function isNumericScalar(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        return is_string($value) && is_numeric(trim($value));
    }
}
