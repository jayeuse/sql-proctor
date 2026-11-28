<?php

namespace App\Http\Resources;

use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Classroom
 */
class ClassroomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'examiner_id' => $this->examiner_id,
            'name' => $this->name,
            'join_code' => $this->join_code,
            'is_open' => (bool) $this->is_open,
            'code_expires_at' => $this->code_expires_at?->toIso8601String(),
            'active_students_count' => $this->whenCounted('activeStudentSessions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
