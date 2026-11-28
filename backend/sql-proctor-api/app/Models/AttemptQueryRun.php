<?php

namespace App\Models;

use App\Enums\QueryRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'attempt_id',
    'question_id',
    'sql_text',
    'status',
    'row_count',
    'execution_ms',
    'error_message',
    'created_at',
])]
class AttemptQueryRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QueryRunStatus::class,
            'row_count' => 'integer',
            'execution_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ExamAttempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }
}
