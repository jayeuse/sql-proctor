<?php

namespace App\Models;

use App\Enums\AnswerGradingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'attempt_id',
    'question_id',
    'submitted_sql',
    'submitted_text',
    'selected_option_ids',
    'submitted_at',
    'auto_score',
    'manual_score',
    'final_score',
    'max_points',
    'grading_status',
    'test_results',
    'rubric_scores',
    'examiner_feedback',
    'override_reason',
    'graded_by',
    'graded_at',
])]
class AttemptAnswer extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'submitted_at' => 'datetime',
            'auto_score' => 'decimal:2',
            'manual_score' => 'decimal:2',
            'final_score' => 'decimal:2',
            'max_points' => 'decimal:2',
            'grading_status' => AnswerGradingStatus::class,
            'test_results' => 'array',
            'rubric_scores' => 'array',
            'graded_at' => 'datetime',
        ];
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * @return BelongsTo<ExamAttempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
