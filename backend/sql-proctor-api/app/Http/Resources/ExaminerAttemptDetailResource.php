<?php

namespace App\Http\Resources;

use App\Models\ExamAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full examiner grading detail including reference_sql, all visible/hidden test results, and query runs.
 *
 * @mixin ExamAttempt
 */
class ExaminerAttemptDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->examVersion?->snapshot ?? [];
        $questions = collect($snapshot['questions'] ?? [])->keyBy('id');
        $answersByQuestion = $this->answers->keyBy('question_id');
        $orderedIds = is_array($this->question_order) && $this->question_order !== []
            ? $this->question_order
            : $questions->keys()->all();

        $items = [];
        foreach ($orderedIds as $qId) {
            $q = $questions->get($qId);
            if (! is_array($q)) {
                continue;
            }
            $answer = $answersByQuestion->get($qId);
            $items[] = [
                'question' => $q,
                'answer' => $answer ? (new AttemptAnswerResource($answer))->resolve($request) : null,
            ];
        }

        return [
            'id' => $this->id,
            'classroom_exam_id' => $this->classroom_exam_id,
            'exam_id' => $this->classroomExam?->exam_id,
            'exam_title' => $snapshot['title'] ?? 'Exam',
            'passing_score_percent' => $snapshot['passing_score_percent'] ?? 60.0,
            'student_display_name' => $this->studentSession?->display_name,
            'classroom' => $this->classroomExam?->classroom ? [
                'id' => $this->classroomExam->classroom->id,
                'name' => $this->classroomExam->classroom->name,
            ] : null,
            'results_released_at' => $this->classroomExam?->results_released_at?->toIso8601ZuluString(),
            'status' => $this->status->value,
            'grading_status' => $this->grading_status->value,
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'auto_score' => number_format((float) $this->auto_score, 2, '.', ''),
            'manual_score' => $this->manual_score !== null ? number_format((float) $this->manual_score, 2, '.', '') : null,
            'final_score' => number_format((float) $this->final_score, 2, '.', ''),
            'max_score' => number_format((float) $this->max_score, 2, '.', ''),
            'passed' => $this->passed,
            'graded_at' => $this->graded_at?->toIso8601ZuluString(),
            'items' => $items,
            'query_runs' => $this->whenLoaded('queryRuns', fn () => $this->queryRuns->take(100)->map(static fn ($run): array => [
                'id' => $run->id,
                'question_id' => $run->question_id,
                'sql_text' => $run->sql_text,
                'status' => $run->status->value,
                'row_count' => $run->row_count,
                'execution_ms' => $run->execution_ms,
                'error_message' => $run->error_message,
                'created_at' => $run->created_at?->toIso8601ZuluString(),
            ])->values()->all()),
        ];
    }
}
