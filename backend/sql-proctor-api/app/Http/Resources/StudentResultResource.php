<?php

namespace App\Http\Resources;

use App\Enums\AttemptGradingStatus;
use App\Models\ExamAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing result serializer (enforces release gate and strict anti-leakage).
 *
 * @mixin ExamAttempt
 */
class StudentResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->examVersion?->snapshot ?? [];
        $settings = is_array($snapshot['settings'] ?? null) ? $snapshot['settings'] : [];
        $classroomExam = $this->classroomExam;

        $released = $classroomExam?->results_released_at !== null
            || ((bool) ($settings['immediate_auto_results'] ?? false) && $this->grading_status === AttemptGradingStatus::Finalized);

        $base = [
            'id' => $this->id,
            'classroom_exam_id' => $this->classroom_exam_id,
            'title' => (string) ($snapshot['title'] ?? 'SQL Exam'),
            'status' => $this->status->value,
            'grading_status' => $this->grading_status->value,
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'released' => $released,
        ];

        if (! $released) {
            $base['message'] = 'Submitted — awaiting examiner result release.';

            return $base;
        }

        $questionsById = collect($snapshot['questions'] ?? [])->keyBy('id');
        $answersByQuestion = $this->answers->keyBy('question_id');
        $orderedIds = is_array($this->question_order) && $this->question_order !== []
            ? $this->question_order
            : $questionsById->keys()->all();

        $questionResults = [];
        foreach ($orderedIds as $index => $questionId) {
            $q = $questionsById->get($questionId);
            if (! is_array($q)) {
                continue;
            }
            $ans = $answersByQuestion->get($questionId);
            $rawTests = is_array($ans?->test_results) ? $ans->test_results : [];

            $visibleTests = [];
            $hiddenTotal = 0;
            $hiddenPassed = 0;

            foreach ($rawTests as $tc) {
                if ((bool) ($tc['is_hidden'] ?? false)) {
                    $hiddenTotal++;
                    if ((bool) ($tc['passed'] ?? false)) {
                        $hiddenPassed++;
                    }

                    continue;
                }

                $visibleTests[] = [
                    'name' => $tc['name'] ?? 'Test Case',
                    'passed' => (bool) ($tc['passed'] ?? false),
                    'points_earned' => number_format((float) ($tc['points_earned'] ?? 0), 2, '.', ''),
                    'weight_points' => number_format((float) ($tc['weight_points'] ?? 0), 2, '.', ''),
                    'message' => $tc['message'] ?? null,
                ];
            }

            $selectedIds = array_map('strval', $ans?->selected_option_ids ?? []);
            $selectedOptions = collect($q['options'] ?? [])
                ->filter(fn (array $opt): bool => in_array((string) ($opt['id'] ?? ''), $selectedIds, true))
                ->map(fn (array $opt): array => [
                    'id' => (string) $opt['id'],
                    'label' => (string) ($opt['label'] ?? ''),
                ])
                ->values()
                ->all();

            $questionResults[] = [
                'question_id' => $questionId,
                'position' => $index + 1,
                'title' => $q['title'],
                'question_type' => $q['question_type'],
                'max_points' => number_format((float) $q['points'], 2, '.', ''),
                'final_score' => number_format((float) ($ans?->final_score ?? 0), 2, '.', ''),
                'grading_status' => $ans?->grading_status?->value ?? 'unanswered',
                'submitted_sql' => $ans?->submitted_sql,
                'submitted_text' => $ans?->submitted_text,
                'selected_option_ids' => $ans?->selected_option_ids ?? [],
                'selected_options' => $selectedOptions,
                'examiner_feedback' => $ans?->examiner_feedback,
                'visible_test_results' => $visibleTests,
                'hidden_tests_summary' => [
                    'total' => $hiddenTotal,
                    'passed' => $hiddenPassed,
                ],
            ];
        }

        $maxScore = (float) $this->max_score;
        $finalScore = (float) $this->final_score;
        $percentage = $maxScore > 0 ? round(($finalScore / $maxScore) * 100, 2) : 0.0;

        return array_merge($base, [
            'final_score' => number_format($finalScore, 2, '.', ''),
            'max_score' => number_format($maxScore, 2, '.', ''),
            'percentage' => number_format($percentage, 2, '.', ''),
            'passing_score_percent' => number_format((float) ($snapshot['passing_score_percent'] ?? 60), 2, '.', ''),
            'passed' => $this->passed,
            'graded_at' => $this->graded_at?->toIso8601ZuluString(),
            'questions' => $questionResults,
        ]);
    }
}
