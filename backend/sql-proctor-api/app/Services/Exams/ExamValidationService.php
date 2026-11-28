<?php

namespace App\Services\Exams;

use App\Enums\ExpectedBehavior;
use App\Enums\GradingMode;
use App\Enums\QuestionType;
use App\Exceptions\ApiException;
use App\Models\Exam;
use App\Services\SqlExecution\SchemaIntrospector;
use App\Services\SqlExecution\SqlExecutionService;

class ExamValidationService
{
    public function __construct(
        private readonly SqlExecutionService $sqlExecutor,
        private readonly SchemaIntrospector $schemaIntrospector,
    ) {}

    /**
     * Validate structural rules and run a live MariaDB dry-run across dataset, reference_sql, and test cases.
     *
     * @return array<int, string>
     */
    public function validate(Exam $exam): array
    {
        $exam->loadMissing(['dataset', 'questions.testCases', 'questions.options']);
        $issues = [];

        if ($exam->questions->isEmpty() || (float) $exam->total_points <= 0) {
            $issues[] = 'Exam must contain at least one question and total_points must be greater than 0.';
        }

        $hasSqlQuestion = $exam->questions->contains(fn ($q): bool => $q->question_type->isSql());
        $dataset = $exam->dataset;

        if ($hasSqlQuestion && ($dataset === null || trim((string) $dataset->schema_sql) === '')) {
            $issues[] = 'Exams containing SQL questions require an attached dataset with non-empty schema_sql.';
        }

        foreach ($exam->questions as $question) {
            $prefix = "Question #{$question->position} ({$question->title})";

            if ($question->question_type->isSql()) {
                if (in_array($question->grading_mode, [GradingMode::Auto, GradingMode::Hybrid], true)
                    && trim((string) $question->reference_sql) === '') {
                    $issues[] = "{$prefix}: auto/hybrid SQL question requires reference_sql.";
                }

                if (in_array($question->question_type, [QuestionType::SqlDml, QuestionType::SqlDdl], true)
                    && in_array($question->grading_mode, [GradingMode::Auto, GradingMode::Hybrid], true)) {
                    if ($question->testCases->isEmpty()) {
                        $issues[] = "{$prefix}: auto/hybrid DML/DDL questions require at least one test case with assertion_sql.";
                    } else {
                        foreach ($question->testCases as $tc) {
                            if ($tc->expected_behavior !== ExpectedBehavior::ExpectError
                                && trim((string) $tc->assertion_sql) === '') {
                                $issues[] = "{$prefix} / {$tc->name}: DML/DDL test case requires non-empty assertion_sql.";
                            }
                        }
                    }
                }

                if ($question->testCases->isNotEmpty()) {
                    $weightSum = (float) $question->testCases->sum('weight_points');
                    if (abs($weightSum - (float) $question->points) > 0.001) {
                        $issues[] = "{$prefix}: test case weights sum ({$weightSum}) must equal question points ({$question->points}).";
                    }
                }
            }

            if ($question->question_type === QuestionType::Mcq) {
                if ($question->options->count() < 2) {
                    $issues[] = "{$prefix}: MCQ question must have at least 2 options.";
                }
                if (! $question->options->contains(fn ($opt): bool => (bool) $opt->is_correct)) {
                    $issues[] = "{$prefix}: MCQ question must mark at least 1 option as correct.";
                }
            }

            if (in_array($question->grading_mode, [GradingMode::Manual, GradingMode::Hybrid], true)
                && is_array($question->rubric) && $question->rubric !== []) {
                $rubricSum = 0.0;
                foreach ($question->rubric as $item) {
                    $rubricSum += (float) ($item['max_points'] ?? 0);
                }
                if (abs($rubricSum - (float) $question->points) > 0.001) {
                    $issues[] = "{$prefix}: rubric max_points sum ({$rubricSum}) must equal question points ({$question->points}).";
                }
            }
        }

        if ($issues !== []) {
            return $issues;
        }

        // Live MariaDB Dry-Run Gate
        if ($hasSqlQuestion && $dataset !== null) {
            $schemaSql = (string) $dataset->schema_sql;
            $baseSeedSql = (string) ($dataset->seed_sql ?? '');

            $baseProbe = $this->sqlExecutor->execute($schemaSql, $baseSeedSql, 'SELECT 1 AS ok', QuestionType::SqlSelect);
            if (! $baseProbe->succeeded()) {
                $issues[] = 'Dataset schema_sql + seed_sql failed MariaDB dry-run: '.($baseProbe->errorMessage ?? 'unknown error');

                return $issues;
            }

            if ($dataset->schema_summary === null) {
                $summary = $this->schemaIntrospector->introspect($schemaSql, $baseSeedSql);
                $dataset->forceFill([
                    'schema_summary' => $summary,
                    'validated_at' => now(),
                ])->save();
            }

            foreach ($exam->questions as $question) {
                if (! $question->question_type->isSql() || trim((string) $question->reference_sql) === '') {
                    continue;
                }

                $prefix = "Question #{$question->position} ({$question->title})";
                $testCases = $question->testCases;

                if ($testCases->isEmpty()) {
                    $run = $this->sqlExecutor->execute(
                        $schemaSql,
                        $baseSeedSql,
                        (string) $question->reference_sql,
                        $question->question_type,
                    );
                    if (! $run->succeeded()) {
                        $issues[] = "{$prefix}: reference_sql failed MariaDB dry-run: ".($run->errorMessage ?? 'error');
                    }

                    continue;
                }

                foreach ($testCases as $tc) {
                    $seedSql = ($tc->override_seed_sql !== null && trim($tc->override_seed_sql) !== '')
                        ? $tc->override_seed_sql
                        : $baseSeedSql;
                    $assertionSql = ($tc->assertion_sql !== null && trim($tc->assertion_sql) !== '')
                        ? $tc->assertion_sql
                        : null;

                    if ($tc->expected_behavior === ExpectedBehavior::ExpectError) {
                        if ($assertionSql !== null) {
                            $refOnly = $this->sqlExecutor->execute($schemaSql, $seedSql, (string) $question->reference_sql, $question->question_type);
                            if (! $refOnly->succeeded()) {
                                $issues[] = "{$prefix} / {$tc->name}: reference_sql failed MariaDB dry-run: ".($refOnly->errorMessage ?? 'error');

                                continue;
                            }

                            $combined = $this->sqlExecutor->execute($schemaSql, $seedSql, (string) $question->reference_sql, $question->question_type, $assertionSql);
                            if ($combined->status !== 'sql_error') {
                                $issues[] = "{$prefix} / {$tc->name}: expected assertion_sql to trigger a MariaDB error after reference_sql.";
                            }
                        }

                        continue;
                    }

                    $run = $this->sqlExecutor->execute(
                        $schemaSql,
                        $seedSql,
                        (string) $question->reference_sql,
                        $question->question_type,
                        $assertionSql,
                    );

                    if (! $run->succeeded()) {
                        $issues[] = "{$prefix} / {$tc->name}: MariaDB dry-run failed: ".($run->errorMessage ?? 'error');
                    }
                }
            }
        }

        return $issues;
    }

    public function validateOrFail(Exam $exam): void
    {
        $issues = $this->validate($exam);

        if ($issues !== []) {
            throw ApiException::unprocessable(
                $issues[0],
                'exam_validation_failed',
                [
                    'issues' => $issues,
                    'errors' => ['exam' => $issues],
                ],
            );
        }
    }
}
