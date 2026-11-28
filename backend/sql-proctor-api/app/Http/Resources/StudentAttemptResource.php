<?php

namespace App\Http\Resources;

use App\Models\ExamAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing attempt workspace serializer.
 * NEVER exposes reference_sql, hidden test cases, override_seed_sql, assertion_sql, or MCQ is_correct.
 *
 * @mixin ExamAttempt
 */
class StudentAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->examVersion?->snapshot ?? [];
        $settings = is_array($snapshot['settings'] ?? null) ? $snapshot['settings'] : [];
        $showPoints = (bool) ($settings['show_points'] ?? true);
        $questionsById = collect($snapshot['questions'] ?? [])->keyBy('id');
        $orderedIds = is_array($this->question_order) && $this->question_order !== []
            ? $this->question_order
            : $questionsById->keys()->all();

        $answersByQuestion = $this->relationLoaded('answers')
            ? $this->answers->keyBy('question_id')
            : collect();

        $sanitizedQuestions = [];
        foreach ($orderedIds as $index => $questionId) {
            $q = $questionsById->get($questionId);
            if (! is_array($q)) {
                continue;
            }

            $visibleTests = [];
            foreach (($q['test_cases'] ?? []) as $tc) {
                if ((bool) ($tc['is_hidden'] ?? false)) {
                    continue;
                }
                $visibleTests[] = [
                    'id' => $tc['id'],
                    'name' => $tc['name'],
                    'position' => (int) ($tc['position'] ?? 1),
                    'weight_points' => $showPoints ? number_format((float) ($tc['weight_points'] ?? 0), 2, '.', '') : null,
                ];
            }

            $sanitizedOptions = array_map(static fn (array $opt): array => [
                'id' => $opt['id'],
                'position' => (int) ($opt['position'] ?? 1),
                'label' => $opt['label'],
            ], $q['options'] ?? []);

            $saved = $answersByQuestion->get($questionId);

            $sanitizedQuestions[] = [
                'id' => $q['id'],
                'position' => $index + 1,
                'question_type' => $q['question_type'],
                'grading_mode' => $q['grading_mode'],
                'title' => $q['title'],
                'prompt' => $q['prompt'],
                'points' => $showPoints ? number_format((float) $q['points'], 2, '.', '') : null,
                'starter_sql' => $q['starter_sql'] ?? null,
                'options' => $sanitizedOptions,
                'visible_test_cases' => $visibleTests,
                'saved_answer' => $saved ? [
                    'submitted_sql' => $saved->submitted_sql,
                    'submitted_text' => $saved->submitted_text,
                    'selected_option_ids' => $saved->selected_option_ids ?? [],
                    'submitted_at' => $saved->submitted_at?->toIso8601ZuluString(),
                    'is_submitted' => $saved->isSubmitted(),
                    'updated_at' => $saved->updated_at?->toIso8601ZuluString(),
                ] : null,
            ];
        }

        return [
            'id' => $this->id,
            'classroom_exam_id' => $this->classroom_exam_id,
            'title' => (string) ($snapshot['title'] ?? 'SQL Exam'),
            'description' => $snapshot['description'] ?? null,
            'instructions' => $snapshot['instructions'] ?? null,
            'duration_minutes' => (int) ($snapshot['duration_minutes'] ?? 0),
            'passing_score_percent' => number_format((float) ($snapshot['passing_score_percent'] ?? 60), 2, '.', ''),
            'max_score' => number_format((float) $this->max_score, 2, '.', ''),
            'status' => $this->status->value,
            'grading_status' => $this->grading_status->value,
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'remaining_seconds' => $this->remainingSeconds(),
            'settings' => [
                'allow_query_preview' => (bool) ($settings['allow_query_preview'] ?? true),
                'show_points' => $showPoints,
                'immediate_auto_results' => (bool) ($settings['immediate_auto_results'] ?? false),
                'max_query_runs_per_question' => $settings['max_query_runs_per_question'] ?? null,
            ],
            'schema_summary' => $snapshot['dataset']['schema_summary'] ?? null,
            'questions' => $sanitizedQuestions,
        ];
    }
}
