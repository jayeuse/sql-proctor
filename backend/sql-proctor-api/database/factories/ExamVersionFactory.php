<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamVersion>
 */
class ExamVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'version_number' => 1,
            'snapshot' => [
                'title' => 'Snapshot Exam',
                'duration_minutes' => 60,
                'passing_score_percent' => 60.00,
                'total_points' => 10.00,
                'settings' => [
                    'allow_query_preview' => true,
                    'shuffle_questions' => false,
                    'show_points' => true,
                    'immediate_auto_results' => false,
                    'max_query_runs_per_question' => null,
                ],
                'dataset' => null,
                'questions' => [],
            ],
            'published_by' => User::factory()->examiner(),
            'published_at' => now(),
        ];
    }
}
