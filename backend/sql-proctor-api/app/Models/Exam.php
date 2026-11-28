<?php

namespace App\Models;

use App\Enums\ExamStatus;
use Database\Factories\ExamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'examiner_id',
    'dataset_id',
    'title',
    'description',
    'instructions',
    'status',
    'duration_minutes',
    'passing_score_percent',
    'total_points',
    'settings',
    'current_version_number',
    'published_at',
    'unpublished_at',
    'archived_at',
])]
class Exam extends Model
{
    /** @use HasFactory<ExamFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExamStatus::class,
            'duration_minutes' => 'integer',
            'passing_score_percent' => 'decimal:2',
            'total_points' => 'decimal:2',
            'settings' => 'array',
            'current_version_number' => 'integer',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function examiner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examiner_id');
    }

    /**
     * @return BelongsTo<SqlDataset, $this>
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(SqlDataset::class, 'dataset_id');
    }

    /**
     * @return HasMany<ExamQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'exam_id')->orderBy('position');
    }

    /**
     * @return HasMany<ExamVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ExamVersion::class, 'exam_id')->orderByDesc('version_number');
    }

    /**
     * @return HasMany<ClassroomExam, $this>
     */
    public function classroomExams(): HasMany
    {
        return $this->hasMany(ClassroomExam::class, 'exam_id');
    }

    /**
     * @return HasManyThrough<ExamAttempt, ClassroomExam, $this>
     */
    public function attempts(): HasManyThrough
    {
        return $this->hasManyThrough(
            ExamAttempt::class,
            ClassroomExam::class,
            'exam_id',
            'classroom_exam_id',
        );
    }

    public function latestVersion(): ?ExamVersion
    {
        return $this->versions()->first();
    }

    public function isEditable(): bool
    {
        if ($this->status === ExamStatus::Draft) {
            return true;
        }

        if ($this->status === ExamStatus::Unpublished) {
            if (array_key_exists('attempts_count', $this->attributes)) {
                return (int) $this->attributes['attempts_count'] === 0;
            }

            return ! $this->attempts()->exists();
        }

        return false;
    }

    public function isDeletable(): bool
    {
        if ($this->status !== ExamStatus::Draft) {
            return false;
        }

        if (array_key_exists('attempts_count', $this->attributes)) {
            return (int) $this->attributes['attempts_count'] === 0;
        }

        return ! $this->attempts()->exists();
    }

    public function recomputeTotalPoints(): void
    {
        $total = $this->questions()->sum('points');
        $this->forceFill(['total_points' => number_format((float) $total, 2, '.', '')])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedSettings(): array
    {
        return array_merge([
            'allow_query_preview' => true,
            'shuffle_questions' => false,
            'show_points' => true,
            'immediate_auto_results' => false,
            'max_query_runs_per_question' => null,
        ], is_array($this->settings) ? $this->settings : []);
    }
}
