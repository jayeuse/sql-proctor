<?php

namespace App\Http\Resources;

use App\Models\StudentSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StudentSession
 */
class StudentSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'classroom' => $this->whenLoaded('classroom', fn () => [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
                'join_code' => $this->classroom->join_code,
                'is_open' => (bool) $this->classroom->is_open,
            ]),
        ];
    }
}
