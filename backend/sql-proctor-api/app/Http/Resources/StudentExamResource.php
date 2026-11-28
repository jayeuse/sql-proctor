<?php

namespace App\Http\Resources;

use App\Enums\AttemptGradingStatus;
use App\Models\ClassroomExam;
use App\Models\ExamAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing assigned exam serializer (strict anti-leakage).
 *
 * @mixin ClassroomExam
 */
class StudentExamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->examVersion?->snapshot ?? [];
        $settings = is_array($snapshot['settings'] ?? null) ? $snapshot['settings'] : [];
        /** @var ?ExamAttempt $attempt */
        $attempt = $this->attempts->first();

        $attemptData = null;
        if ($attempt instanceof ExamAttempt) {
            $released = $this->results_released_at !== null
                || ((bool) ($settings['immediate_auto_results'] ?? false) && $attempt->grading_status === AttemptGradingStatus::Finalized);

            $attemptData = [
                'id' => $attempt->id,
                'status' => $attempt->status->value,
                'grading_status' => $attempt->grading_status->value,
                'started_at' => $attempt->started_at?->toIso8601ZuluString(),
                'expires_at' => $attempt->expires_at?->toIso8601ZuluString(),
                'submitted_at' => $attempt->submitted_at?->toIso8601ZuluString(),
                'remaining_seconds' => $attempt->remainingSeconds(),
                'released' => $released,
            ];

            if ($released) {
                $attemptData['final_score'] = number_format((float) $attempt->final_score, 2, '.', '');
                $attemptData['max_score'] = number_format((float) $attempt->max_score, 2, '.', '');
                $attemptData['passed'] = $attempt->passed;
            }
        }

        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'title' => (string) ($snapshot['title'] ?? $this->exam?->title ?? 'SQL Exam'),
            'description' => $snapshot['description'] ?? $this->exam?->description,
            'instructions' => $snapshot['instructions'] ?? $this->exam?->instructions,
            'duration_minutes' => (int) ($snapshot['duration_minutes'] ?? $this->exam?->duration_minutes ?? 0),
            'passing_score_percent' => number_format((float) ($snapshot['passing_score_percent'] ?? 60), 2, '.', ''),
            'total_points' => number_format((float) ($snapshot['total_points'] ?? 0), 2, '.', ''),
            'question_count' => count($snapshot['questions'] ?? []),
            'is_active' => (bool) $this->is_active,
            'available_from' => $this->available_from?->toIso8601ZuluString(),
            'available_until' => $this->available_until?->toIso8601ZuluString(),
            'results_released_at' => $this->results_released_at?->toIso8601ZuluString(),
            'settings' => [
                'allow_query_preview' => (bool) ($settings['allow_query_preview'] ?? true),
                'show_points' => (bool) ($settings['show_points'] ?? true),
                'immediate_auto_results' => (bool) ($settings['immediate_auto_results'] ?? false),
                'max_query_runs_per_question' => $settings['max_query_runs_per_question'] ?? null,
            ],
            'attempt' => $attemptData,
        ];
    }
}
