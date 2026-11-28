<?php

namespace App\Http\Resources;

use App\Models\ClassroomExam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassroomExam
 */
class ClassroomExamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'classroom' => $this->whenLoaded('classroom', fn () => [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
                'join_code' => $this->classroom->join_code,
                'is_open' => (bool) $this->classroom->is_open,
            ]),
            'exam_id' => $this->exam_id,
            'exam_version_id' => $this->exam_version_id,
            'version_number' => $this->whenLoaded('examVersion', fn () => (int) $this->examVersion->version_number),
            'is_active' => (bool) $this->is_active,
            'available_from' => $this->available_from?->toIso8601ZuluString(),
            'available_until' => $this->available_until?->toIso8601ZuluString(),
            'results_released_at' => $this->results_released_at?->toIso8601ZuluString(),
            'attempts_count' => $this->whenCounted('attempts'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
