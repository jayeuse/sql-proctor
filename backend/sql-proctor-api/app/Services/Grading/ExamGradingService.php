<?php

namespace App\Services\Grading;

use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptGradingStatus;
use App\Enums\ExpectedBehavior;
use App\Enums\GradingMode;
use App\Enums\QuestionType;
use App\Models\AttemptAnswer;
use App\Models\ExamAttempt;
use App\Services\SqlExecution\SqlExecutionService;

class ExamGradingService
{
    public function __construct(
        private readonly SqlExecutionService $sqlExecutor,
        private readonly ResultSetComparator $comparator,
    ) {}

    public function gradeAttempt(ExamAttempt $attempt): ExamAttempt
    {
        $attempt->loadMissing(['examVersion', 'answers']);
        $snapshot = $attempt->examVersion->snapshot;
        $questions = is_array($snapshot['questions'] ?? null) ? $snapshot['questions'] : [];
        $dataset = is_array($snapshot['dataset'] ?? null) ? $snapshot['dataset'] : null;

        $answersByQuestion = $attempt->answers->keyBy('question_id');

        $totalAuto = 0.0;
        $totalManual = 0.0;
        $hasManual = false;
        $totalFinal = 0.0;
        $needsReview = false;

        foreach ($questions as $question) {
            $questionId = (string) $question['id'];
            $maxPoints = round((float) $question['points'], 2);
            $type = QuestionType::from((string) $question['question_type']);
            $mode = GradingMode::from((string) $question['grading_mode']);

            /** @var AttemptAnswer $answer */
            $answer = $answersByQuestion->get($questionId) ?? AttemptAnswer::create([
                'attempt_id' => $attempt->id,
                'question_id' => $questionId,
                'max_points' => $maxPoints,
                'grading_status' => AnswerGradingStatus::Unanswered,
                'final_score' => 0.00,
                'test_results' => [],
            ]);

            if ($type === QuestionType::ShortAnswer || $mode === GradingMode::Manual) {
                $answer->forceFill([
                    'auto_score' => null,
                    'final_score' => $answer->manual_score !== null ? (float) $answer->manual_score : 0.00,
                    'grading_status' => $answer->manual_score !== null ? AnswerGradingStatus::Graded : AnswerGradingStatus::NeedsReview,
                ])->save();

                if ($answer->manual_score !== null) {
                    $hasManual = true;
                    $totalManual += (float) $answer->manual_score;
                } else {
                    $needsReview = true;
                }
                $totalFinal += (float) $answer->final_score;

                continue;
            }

            if ($type === QuestionType::Mcq) {
                $score = $this->gradeMcq($question, $answer->selected_option_ids ?? []);
                $status = $mode === GradingMode::Hybrid
                    ? AnswerGradingStatus::NeedsReview
                    : AnswerGradingStatus::AutoGraded;

                $answer->forceFill([
                    'auto_score' => $this->fmt($score),
                    'final_score' => $this->fmt($score),
                    'grading_status' => $status,
                    'test_results' => [],
                ])->save();

                $totalAuto += $score;
                $totalFinal += (float) $answer->final_score;
                if ($status === AnswerGradingStatus::NeedsReview) {
                    $needsReview = true;
                }

                continue;
            }

            // SQL question (auto or hybrid)
            [$autoScore, $testResults] = $this->gradeSqlQuestion($question, $dataset, (string) ($answer->submitted_sql ?? ''));
            $status = $mode === GradingMode::Hybrid
                ? AnswerGradingStatus::NeedsReview
                : AnswerGradingStatus::AutoGraded;

            $answer->forceFill([
                'auto_score' => $this->fmt($autoScore),
                'final_score' => $this->fmt($autoScore),
                'grading_status' => $status,
                'test_results' => $testResults,
            ])->save();

            $totalAuto += $autoScore;
            $totalFinal += (float) $answer->final_score;
            if ($status === AnswerGradingStatus::NeedsReview) {
                $needsReview = true;
            }
        }

        $maxScore = (float) $attempt->max_score;
        $passingPercent = (float) ($snapshot['passing_score_percent'] ?? 60.0);
        $passed = null;
        $attemptGradingStatus = AttemptGradingStatus::NeedsReview;
        $gradedAt = null;

        if (! $needsReview) {
            $attemptGradingStatus = AttemptGradingStatus::Finalized;
            $percentage = $maxScore > 0 ? ($totalFinal / $maxScore) * 100.0 : 0.0;
            $passed = $percentage >= $passingPercent;
            $gradedAt = now();
        }

        $attempt->forceFill([
            'auto_score' => $this->fmt($totalAuto),
            'manual_score' => $hasManual ? $this->fmt($totalManual) : null,
            'final_score' => $this->fmt($totalFinal),
            'grading_status' => $attemptGradingStatus,
            'passed' => $passed,
            'graded_at' => $gradedAt,
        ])->save();

        return $attempt->refresh();
    }

    public function recalculateAttemptTotals(ExamAttempt $attempt, ?int $graderId = null, bool $finalize = false): ExamAttempt
    {
        $attempt->loadMissing(['examVersion', 'answers']);
        $snapshot = $attempt->examVersion->snapshot;
        $passingPercent = (float) ($snapshot['passing_score_percent'] ?? 60.0);

        $totalAuto = 0.0;
        $totalManual = 0.0;
        $hasManual = false;
        $totalFinal = 0.0;
        $hasNeedsReview = false;

        foreach ($attempt->answers as $answer) {
            if ($answer->auto_score !== null) {
                $totalAuto += (float) $answer->auto_score;
            }
            if ($answer->manual_score !== null) {
                $hasManual = true;
                $totalManual += (float) $answer->manual_score;
            }
            $totalFinal += (float) $answer->final_score;

            if ($answer->grading_status === AnswerGradingStatus::NeedsReview) {
                $hasNeedsReview = true;
            }
        }

        $maxScore = (float) $attempt->max_score;
        $percentage = $maxScore > 0 ? ($totalFinal / $maxScore) * 100.0 : 0.0;

        $gradingStatus = $attempt->grading_status;
        $passed = $attempt->passed;
        $gradedAt = $attempt->graded_at;

        if (($finalize || $gradingStatus === AttemptGradingStatus::Finalized) && ! $hasNeedsReview) {
            $gradingStatus = AttemptGradingStatus::Finalized;
            $passed = $percentage >= $passingPercent;
            $gradedAt = $finalize ? now() : ($gradedAt ?? now());
        }

        $attempt->forceFill([
            'auto_score' => $this->fmt($totalAuto),
            'manual_score' => $hasManual ? $this->fmt($totalManual) : null,
            'final_score' => $this->fmt($totalFinal),
            'grading_status' => $gradingStatus,
            'passed' => $passed,
            'graded_by' => $graderId ?? $attempt->graded_by,
            'graded_at' => $gradedAt,
        ])->save();

        return $attempt->refresh();
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<int, mixed>  $selectedIds
     */
    private function gradeMcq(array $question, array $selectedIds): float
    {
        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        $correctIds = [];
        foreach ($options as $option) {
            if ((bool) ($option['is_correct'] ?? false) === true) {
                $correctIds[] = (string) $option['id'];
            }
        }

        $normalizedSelected = array_values(array_unique(array_map('strval', $selectedIds)));
        sort($correctIds);
        sort($normalizedSelected);

        if ($correctIds !== [] && $correctIds === $normalizedSelected) {
            return round((float) $question['points'], 2);
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  ?array<string, mixed>  $dataset
     * @return array{0: float, 1: array<int, array<string, mixed>>}
     */
    private function gradeSqlQuestion(array $question, ?array $dataset, string $submittedSql): array
    {
        $testCases = is_array($question['test_cases'] ?? null) ? $question['test_cases'] : [];
        if ($testCases === []) {
            $testCases = [[
                'id' => $question['id'],
                'name' => 'Default evaluation',
                'weight_points' => (float) $question['points'],
                'is_hidden' => false,
                'override_seed_sql' => null,
                'assertion_sql' => null,
                'expected_behavior' => ExpectedBehavior::MatchReference->value,
            ]];
        }

        $schemaSql = (string) ($dataset['schema_sql'] ?? '');
        $baseSeedSql = (string) ($dataset['seed_sql'] ?? '');
        $comparisonConfig = is_array($question['comparison_config'] ?? null) ? $question['comparison_config'] : [];

        if (trim($submittedSql) === '') {
            $results = array_map(static fn (array $tc): array => [
                'test_case_id' => $tc['id'],
                'name' => $tc['name'],
                'is_hidden' => (bool) ($tc['is_hidden'] ?? false),
                'passed' => false,
                'points_earned' => 0.00,
                'weight_points' => round((float) $tc['weight_points'], 2),
                'execution_ms' => 0,
                'message' => 'No SQL submitted.',
            ], $testCases);

            return [0.0, $results];
        }

        $constraintCheck = $this->comparator->checkSqlConstraints($submittedSql, $comparisonConfig);
        if (! $constraintCheck['passed']) {
            $results = array_map(static fn (array $tc): array => [
                'test_case_id' => $tc['id'],
                'name' => $tc['name'],
                'is_hidden' => (bool) ($tc['is_hidden'] ?? false),
                'passed' => false,
                'points_earned' => 0.00,
                'weight_points' => round((float) $tc['weight_points'], 2),
                'execution_ms' => 0,
                'message' => $constraintCheck['reason'],
            ], $testCases);

            return [0.0, $results];
        }

        $type = QuestionType::from((string) $question['question_type']);
        $referenceSql = (string) ($question['reference_sql'] ?? '');

        $earnedTotal = 0.0;
        $results = [];

        foreach ($testCases as $tc) {
            $weight = round((float) $tc['weight_points'], 2);
            $isHidden = (bool) ($tc['is_hidden'] ?? false);
            $behavior = ExpectedBehavior::from((string) ($tc['expected_behavior'] ?? 'match_reference'));
            $overrideSeed = $tc['override_seed_sql'] ?? null;
            $seedSql = ($overrideSeed !== null && trim((string) $overrideSeed) !== '')
                ? (string) $overrideSeed
                : $baseSeedSql;
            $assertionSql = isset($tc['assertion_sql']) && trim((string) $tc['assertion_sql']) !== ''
                ? (string) $tc['assertion_sql']
                : null;

            $eval = $this->evaluateTestCase(
                schemaSql: $schemaSql,
                seedSql: $seedSql,
                questionType: $type,
                referenceSql: $referenceSql,
                submittedSql: $submittedSql,
                assertionSql: $assertionSql,
                behavior: $behavior,
                comparisonConfig: $comparisonConfig,
            );

            $pointsEarned = $eval['passed'] ? $weight : 0.0;
            $earnedTotal += $pointsEarned;

            $results[] = [
                'test_case_id' => $tc['id'],
                'name' => $tc['name'],
                'is_hidden' => $isHidden,
                'passed' => $eval['passed'],
                'points_earned' => $pointsEarned,
                'weight_points' => $weight,
                'execution_ms' => $eval['execution_ms'],
                'message' => $eval['message'],
            ];
        }

        return [round($earnedTotal, 2), $results];
    }

    /**
     * @param  array<string, mixed>  $comparisonConfig
     * @return array{passed: bool, execution_ms: int, message: ?string}
     */
    private function evaluateTestCase(
        string $schemaSql,
        string $seedSql,
        QuestionType $questionType,
        string $referenceSql,
        string $submittedSql,
        ?string $assertionSql,
        ExpectedBehavior $behavior,
        array $comparisonConfig,
    ): array {
        if ($behavior === ExpectedBehavior::ExpectError) {
            if ($assertionSql !== null) {
                $setupRun = $this->sqlExecutor->execute($schemaSql, $seedSql, $submittedSql, $questionType);
                if (! $setupRun->succeeded()) {
                    return [
                        'passed' => false,
                        'execution_ms' => $setupRun->executionMs,
                        'message' => $setupRun->errorMessage ?? 'Submitted SQL failed before constraint test.',
                    ];
                }

                $combinedRun = $this->sqlExecutor->execute($schemaSql, $seedSql, $submittedSql, $questionType, $assertionSql);

                return [
                    'passed' => $combinedRun->status === 'sql_error',
                    'execution_ms' => $combinedRun->executionMs,
                    'message' => $combinedRun->status === 'sql_error'
                        ? 'Expected constraint/error triggered.'
                        : 'Expected constraint error did not occur.',
                ];
            }

            $run = $this->sqlExecutor->execute($schemaSql, $seedSql, $submittedSql, $questionType);

            return [
                'passed' => $run->status === 'sql_error',
                'execution_ms' => $run->executionMs,
                'message' => $run->status === 'sql_error'
                    ? 'Expected SQL error occurred.'
                    : 'Expected SQL error did not occur.',
            ];
        }

        $subRun = $this->sqlExecutor->execute($schemaSql, $seedSql, $submittedSql, $questionType, $assertionSql);
        if (! $subRun->succeeded()) {
            return [
                'passed' => false,
                'execution_ms' => $subRun->executionMs,
                'message' => $subRun->errorMessage ?? 'Execution failed.',
            ];
        }

        if ($behavior === ExpectedBehavior::NonEmpty) {
            $nonEmpty = ($subRun->rowCount ?? 0) > 0;

            return [
                'passed' => $nonEmpty,
                'execution_ms' => $subRun->executionMs,
                'message' => $nonEmpty ? 'Returned non-empty result.' : 'Expected at least 1 row.',
            ];
        }

        $refRun = $this->sqlExecutor->execute($schemaSql, $seedSql, $referenceSql, $questionType, $assertionSql);
        if (! $refRun->succeeded()) {
            return [
                'passed' => false,
                'execution_ms' => $subRun->executionMs,
                'message' => 'Reference evaluation failed.',
            ];
        }

        $match = $this->comparator->matches(
            ['columns' => $refRun->columns, 'rows' => $refRun->rows, 'rawRows' => $refRun->rawRows],
            ['columns' => $subRun->columns, 'rows' => $subRun->rows, 'rawRows' => $subRun->rawRows],
            $comparisonConfig,
        );

        return [
            'passed' => $match['matched'],
            'execution_ms' => $subRun->executionMs,
            'message' => $match['matched'] ? 'Matched expected result.' : $match['reason'],
        ];
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
