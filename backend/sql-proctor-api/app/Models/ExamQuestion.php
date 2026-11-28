<?php

namespace App\Models;

use App\Enums\GradingMode;
use App\Enums\QuestionType;
use Database\Factories\ExamQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'exam_id',
    'position',
    'question_type',
    'grading_mode',
    'title',
    'prompt',
    'points',
    'starter_sql',
    'reference_sql',
    'comparison_config',
    'rubric',
])]
class ExamQuestion extends Model
{
    /** @use HasFactory<ExamQuestionFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'question_type' => QuestionType::class,
            'grading_mode' => GradingMode::class,
            'points' => 'decimal:2',
            'comparison_config' => 'array',
            'rubric' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Exam, $this>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    /**
     * @return HasMany<QuestionTestCase, $this>
     */
    public function testCases(): HasMany
    {
        return $this->hasMany(QuestionTestCase::class, 'question_id')->orderBy('position');
    }

    /**
     * @return HasMany<QuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class, 'question_id')->orderBy('position');
    }
}
