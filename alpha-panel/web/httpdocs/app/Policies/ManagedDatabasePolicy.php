<?php

namespace App\Policies;

use App\Models\ManagedDatabase;
use App\Models\User;

class ManagedDatabasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ManagedDatabase $database): bool
    {
        return $user->isAdmin() || $database->domain->owner_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, ManagedDatabase $database): bool
    {
        return $user->isAdmin() || $database->domain->owner_user_id === $user->id;
    }
}
