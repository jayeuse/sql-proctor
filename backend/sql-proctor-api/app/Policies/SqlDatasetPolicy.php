<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SqlDataset;
use App\Models\User;

class SqlDatasetPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active && in_array($actor->role, [UserRole::Examiner, UserRole::Admin], true);
    }

    public function create(User $actor): bool
    {
        return $actor->is_active && in_array($actor->role, [UserRole::Examiner, UserRole::Admin], true);
    }

    public function view(User $actor, SqlDataset $dataset): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        if ($actor->role === UserRole::Admin) {
            return true;
        }

        return $actor->role === UserRole::Examiner
            && ($dataset->owner_id === $actor->id || $dataset->is_shared);
    }

    public function update(User $actor, SqlDataset $dataset): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        if ($actor->role === UserRole::Admin) {
            return $dataset->is_shared || $dataset->owner_id === $actor->id;
        }

        return $actor->role === UserRole::Examiner && $dataset->owner_id === $actor->id;
    }

    public function delete(User $actor, SqlDataset $dataset): bool
    {
        return $this->update($actor, $dataset);
    }
}
