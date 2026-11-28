<?php

namespace Database\Factories;

use App\Enums\GradingMode;
use App\Enums\QuestionType;
use App\Models\Exam;
use App\Models\ExamQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamQuestion>
 */
class ExamQuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'position' => 1,
            'question_type' => QuestionType::SqlSelect,
            'grading_mode' => GradingMode::Auto,
            'title' => fake()->sentence(4),
            'prompt' => fake()->paragraph(),
            'points' => 10.00,
            'starter_sql' => null,
            'reference_sql' => 'SELECT id, name FROM items ORDER BY id',
            'comparison_config' => [],
            'rubric' => [],
        ];
    }
}
