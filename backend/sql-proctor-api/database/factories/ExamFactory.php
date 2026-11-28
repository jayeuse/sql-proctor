<?php

namespace Database\Factories;

use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exam>
 */
class ExamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'examiner_id' => User::factory()->examiner(),
            'dataset_id' => null,
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'instructions' => 'Write valid MariaDB 11.8 SQL statements.',
            'status' => ExamStatus::Draft,
            'duration_minutes' => 60,
            'passing_score_percent' => 60.00,
            'total_points' => 0.00,
            'settings' => [
                'allow_query_preview' => true,
                'shuffle_questions' => false,
                'show_points' => true,
                'immediate_auto_results' => false,
                'max_query_runs_per_question' => null,
            ],
            'current_version_number' => 0,
            'published_at' => null,
            'unpublished_at' => null,
            'archived_at' => null,
        ];
    }
}
