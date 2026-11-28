<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role === UserRole::Admin && $actor->is_active;
    }

    public function create(User $actor): bool
    {
        return $actor->role === UserRole::Admin && $actor->is_active;
    }

    public function manageExaminer(User $actor, User $target): Response
    {
        if ($actor->role !== UserRole::Admin || ! $actor->is_active) {
            return Response::deny('This action is unauthorized.');
        }

        if ($actor->id === $target->id || $target->role !== UserRole::Examiner) {
            return Response::deny('Cannot modify an administrator account through examiner endpoints.', 'cannot_modify_admin');
        }

        return Response::allow();
    }
}
