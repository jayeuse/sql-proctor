<?php

namespace App\Http\Resources;

use App\Models\SqlDataset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SqlDataset
 */
class SqlDatasetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,
            'owner_username' => $this->whenLoaded('owner', fn () => $this->owner?->username),
            'name' => $this->name,
            'description' => $this->description,
            'schema_sql' => $this->schema_sql,
            'seed_sql' => $this->seed_sql,
            'schema_summary' => $this->schema_summary,
            'is_shared' => (bool) $this->is_shared,
            'validated_at' => $this->validated_at?->toIso8601ZuluString(),
            'exams_count' => $this->whenCounted('exams'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
