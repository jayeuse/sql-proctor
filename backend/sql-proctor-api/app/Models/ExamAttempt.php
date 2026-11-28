<?php

namespace App\Models;

use App\Enums\AttemptGradingStatus;
use App\Enums\AttemptStatus;
use Database\Factories\ExamAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'classroom_exam_id',
    'exam_version_id',
    'student_session_id',
    'status',
    'grading_status',
    'started_at',
    'expires_at',
    'submitted_at',
    'question_order',
    'auto_score',
    'manual_score',
    'final_score',
    'max_score',
    'passed',
    'graded_by',
    'graded_at',
])]
class ExamAttempt extends Model
{
    /** @use HasFactory<ExamAttemptFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'grading_status' => AttemptGradingStatus::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'question_order' => 'array',
            'auto_score' => 'decimal:2',
            'manual_score' => 'decimal:2',
            'final_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'passed' => 'boolean',
            'graded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ClassroomExam, $this>
     */
    public function classroomExam(): BelongsTo
    {
        return $this->belongsTo(ClassroomExam::class, 'classroom_exam_id');
    }

    /**
     * @return BelongsTo<ExamVersion, $this>
     */
    public function examVersion(): BelongsTo
    {
        return $this->belongsTo(ExamVersion::class, 'exam_version_id');
    }

    /**
     * @return BelongsTo<StudentSession, $this>
     */
    public function studentSession(): BelongsTo
    {
        return $this->belongsTo(StudentSession::class, 'student_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /**
     * @return HasMany<AttemptAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class, 'attempt_id');
    }

    /**
     * @return HasMany<AttemptQueryRun, $this>
     */
    public function queryRuns(): HasMany
    {
        return $this->hasMany(AttemptQueryRun::class, 'attempt_id')->orderByDesc('created_at');
    }

    public function isInProgress(): bool
    {
        return $this->status === AttemptStatus::InProgress;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThanOrEqualTo($this->expires_at);
    }

    public function remainingSeconds(): int
    {
        if (! $this->isInProgress() || $this->expires_at === null) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }
}
