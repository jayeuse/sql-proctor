<?php

namespace App\Http\Resources;

use App\Models\AttemptAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttemptAnswer
 */
class AttemptAnswerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_id' => $this->attempt_id,
            'question_id' => $this->question_id,
            'submitted_sql' => $this->submitted_sql,
            'submitted_text' => $this->submitted_text,
            'selected_option_ids' => $this->selected_option_ids ?? [],
            'auto_score' => $this->auto_score !== null ? number_format((float) $this->auto_score, 2, '.', '') : null,
            'manual_score' => $this->manual_score !== null ? number_format((float) $this->manual_score, 2, '.', '') : null,
            'final_score' => number_format((float) $this->final_score, 2, '.', ''),
            'max_points' => number_format((float) $this->max_points, 2, '.', ''),
            'grading_status' => $this->grading_status->value,
            'test_results' => $this->test_results ?? [],
            'rubric_scores' => $this->rubric_scores,
            'examiner_feedback' => $this->examiner_feedback,
            'override_reason' => $this->override_reason,
            'graded_by' => $this->graded_by,
            'graded_at' => $this->graded_at?->toIso8601ZuluString(),
        ];
    }
}
