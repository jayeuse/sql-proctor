<?php

namespace Database\Factories;

use App\Enums\ExpectedBehavior;
use App\Models\ExamQuestion;
use App\Models\QuestionTestCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionTestCase>
 */
class QuestionTestCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'question_id' => ExamQuestion::factory(),
            'name' => 'Base Test Case',
            'position' => 1,
            'weight_points' => 10.00,
            'is_hidden' => false,
            'override_seed_sql' => null,
            'assertion_sql' => null,
            'expected_behavior' => ExpectedBehavior::MatchReference,
        ];
    }
}
