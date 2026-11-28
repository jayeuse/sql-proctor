<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\ClassroomExam;
use App\Models\Exam;
use App\Models\ExamVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassroomExam>
 */
class ClassroomExamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'classroom_id' => Classroom::factory(),
            'exam_id' => Exam::factory(),
            'exam_version_id' => ExamVersion::factory(),
            'is_active' => true,
            'available_from' => null,
            'available_until' => null,
            'results_released_at' => null,
            'assigned_by' => User::factory()->examiner(),
        ];
    }
}
