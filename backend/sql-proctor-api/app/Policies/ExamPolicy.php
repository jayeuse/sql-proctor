<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\User;

class ExamPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active && in_array($actor->role, [UserRole::Examiner, UserRole::Admin], true);
    }

    public function create(User $actor): bool
    {
        return $actor->is_active && $actor->role === UserRole::Examiner;
    }

    public function view(User $actor, Exam $exam): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        if ($actor->role === UserRole::Admin) {
            return true;
        }

        return $actor->role === UserRole::Examiner && $exam->examiner_id === $actor->id;
    }

    public function update(User $actor, Exam $exam): bool
    {
        return $actor->is_active
            && $actor->role === UserRole::Examiner
            && $exam->examiner_id === $actor->id;
    }

    public function delete(User $actor, Exam $exam): bool
    {
        return $this->update($actor, $exam);
    }

    public function forceUnpublish(User $actor, Exam $exam): bool
    {
        return $actor->is_active && $actor->role === UserRole::Admin;
    }
}
