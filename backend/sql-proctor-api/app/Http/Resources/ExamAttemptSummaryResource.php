<?php

namespace App\Http\Resources;

use App\Models\ExamAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExamAttempt
 */
class ExamAttemptSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_exam_id' => $this->classroom_exam_id,
            'exam_version_id' => $this->exam_version_id,
            'student_session_id' => $this->student_session_id,
            'student_display_name' => $this->whenLoaded('studentSession', fn () => $this->studentSession?->display_name),
            'classroom' => $this->whenLoaded('classroomExam', fn () => $this->classroomExam?->relationLoaded('classroom') ? [
                'id' => $this->classroomExam->classroom->id,
                'name' => $this->classroomExam->classroom->name,
            ] : null),
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
        ];
    }
}
