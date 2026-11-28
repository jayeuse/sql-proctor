<?php

namespace Database\Factories;

use App\Enums\AttemptGradingStatus;
use App\Enums\AttemptStatus;
use App\Models\ClassroomExam;
use App\Models\ExamAttempt;
use App\Models\ExamVersion;
use App\Models\StudentSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamAttempt>
 */
class ExamAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'classroom_exam_id' => ClassroomExam::factory(),
            'exam_version_id' => ExamVersion::factory(),
            'student_session_id' => StudentSession::factory(),
            'status' => AttemptStatus::InProgress,
            'grading_status' => AttemptGradingStatus::Pending,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'submitted_at' => null,
            'question_order' => [],
            'auto_score' => 0.00,
            'manual_score' => null,
            'final_score' => 0.00,
            'max_score' => 10.00,
            'passed' => null,
            'graded_by' => null,
            'graded_at' => null,
        ];
    }
}
