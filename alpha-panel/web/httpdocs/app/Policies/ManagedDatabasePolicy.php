<?php

namespace App\Policies;

use App\Models\ManagedDatabase;
use App\Models\User;

class ManagedDatabasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('domain.databases.view');
    }

    public function view(User $user, ManagedDatabase $database): bool
    {
        $domain = $database->domain;

        $canAccess = $domain->owner_user_id === $user->id
            || $domain->authorizedUsers()->where('user_id', $user->id)->exists();

        return $canAccess && $user->can('domain.databases.view');
    }

    public function create(User $user): bool
    {
        return $user->can('domain.databases.manage');
    }

    public function delete(User $user, ManagedDatabase $database): bool
    {
        $domain = $database->domain;

        $canAccess = $domain->owner_user_id === $user->id
            || $domain->authorizedUsers()->where('user_id', $user->id)->exists();

        return $canAccess && $user->can('domain.databases.manage');
    }
}
