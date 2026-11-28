<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ExamAttempt;
use App\Models\User;

class ExamAttemptPolicy
{
    public function view(User $actor, ExamAttempt $attempt): bool
    {
        if (! $actor->is_active || $actor->role !== UserRole::Examiner) {
            return false;
        }

        $attempt->loadMissing('classroomExam.exam');

        return $attempt->classroomExam?->exam?->examiner_id === $actor->id;
    }

    public function grade(User $actor, ExamAttempt $attempt): bool
    {
        return $this->view($actor, $attempt);
    }
}
