<?php

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Exam
 */
class ExamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'examiner_id' => $this->examiner_id,
            'examiner' => $this->whenLoaded('examiner', fn () => $this->examiner ? [
                'id' => $this->examiner->id,
                'username' => $this->examiner->username,
                'name' => $this->examiner->name,
            ] : null),
            'dataset_id' => $this->dataset_id,
            'dataset' => $this->whenLoaded('dataset', fn () => $this->dataset ? new SqlDatasetResource($this->dataset) : null),
            'title' => $this->title,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'status' => $this->status->value,
            'duration_minutes' => (int) $this->duration_minutes,
            'passing_score_percent' => number_format((float) $this->passing_score_percent, 2, '.', ''),
            'total_points' => number_format((float) $this->total_points, 2, '.', ''),
            'settings' => $this->resolvedSettings(),
            'current_version_number' => (int) $this->current_version_number,
            'is_editable' => $this->isEditable(),
            'questions_count' => $this->whenCounted('questions'),
            'attempts_count' => $this->whenCounted('attempts'),
            'questions' => $this->whenLoaded('questions', fn () => ExamQuestionResource::collection($this->questions)),
            'classroom_exams' => $this->whenLoaded('classroomExams', fn () => ClassroomExamResource::collection($this->classroomExams)),
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'unpublished_at' => $this->unpublished_at?->toIso8601ZuluString(),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
