<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Classroom;
use App\Models\User;

class ClassroomPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role === UserRole::Examiner && $actor->is_active;
    }

    public function create(User $actor): bool
    {
        return $actor->role === UserRole::Examiner && $actor->is_active;
    }

    public function view(User $actor, Classroom $classroom): bool
    {
        return $actor->role === UserRole::Examiner
            && $actor->is_active
            && $classroom->examiner_id === $actor->id;
    }

    public function update(User $actor, Classroom $classroom): bool
    {
        return $this->view($actor, $classroom);
    }
}
