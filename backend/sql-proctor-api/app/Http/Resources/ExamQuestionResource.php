<?php

namespace App\Http\Resources;

use App\Models\ExamQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Staff-only exam question serializer (includes reference_sql, hidden test cases, and MCQ is_correct).
 *
 * @mixin ExamQuestion
 */
class ExamQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'position' => (int) $this->position,
            'question_type' => $this->question_type->value,
            'grading_mode' => $this->grading_mode->value,
            'title' => $this->title,
            'prompt' => $this->prompt,
            'points' => number_format((float) $this->points, 2, '.', ''),
            'starter_sql' => $this->starter_sql,
            'reference_sql' => $this->reference_sql,
            'comparison_config' => $this->comparison_config ?? [],
            'rubric' => $this->rubric ?? [],
            'test_cases' => $this->whenLoaded('testCases', fn () => $this->testCases->map(static fn ($tc): array => [
                'id' => $tc->id,
                'name' => $tc->name,
                'position' => (int) $tc->position,
                'weight_points' => number_format((float) $tc->weight_points, 2, '.', ''),
                'is_hidden' => (bool) $tc->is_hidden,
                'override_seed_sql' => $tc->override_seed_sql,
                'assertion_sql' => $tc->assertion_sql,
                'expected_behavior' => $tc->expected_behavior->value,
            ])->values()->all()),
            'options' => $this->whenLoaded('options', fn () => $this->options->map(static fn ($opt): array => [
                'id' => $opt->id,
                'position' => (int) $opt->position,
                'label' => $opt->label,
                'is_correct' => (bool) $opt->is_correct,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
