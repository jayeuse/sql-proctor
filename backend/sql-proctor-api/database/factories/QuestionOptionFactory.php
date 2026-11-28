<?php

namespace Database\Factories;

use App\Models\ExamQuestion;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionOption>
 */
class QuestionOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'question_id' => ExamQuestion::factory(),
            'position' => 1,
            'label' => fake()->sentence(4),
            'is_correct' => false,
        ];
    }
}
