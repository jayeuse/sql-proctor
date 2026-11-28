<?php

namespace App\Models;

use App\Enums\ExpectedBehavior;
use Database\Factories\QuestionTestCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'question_id',
    'name',
    'position',
    'weight_points',
    'is_hidden',
    'override_seed_sql',
    'assertion_sql',
    'expected_behavior',
])]
class QuestionTestCase extends Model
{
    /** @use HasFactory<QuestionTestCaseFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'weight_points' => 'decimal:2',
            'is_hidden' => 'boolean',
            'expected_behavior' => ExpectedBehavior::class,
        ];
    }

    /**
     * @return BelongsTo<ExamQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id');
    }
}
