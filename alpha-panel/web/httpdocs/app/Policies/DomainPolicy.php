<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;

class DomainPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Domain $domain): bool
    {
        return $user->isAdmin() || $domain->owner_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Domain $domain): bool
    {
        return $user->isAdmin() || $domain->owner_user_id === $user->id;
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $user->isAdmin() || $domain->owner_user_id === $user->id;
    }

    public function provision(User $user, Domain $domain): bool
    {
        return $user->isAdmin() || $domain->owner_user_id === $user->id;
    }

    public function manageDns(User $user, Domain $domain): bool
    {
        $canAccessDomain = $user->isAdmin() || $domain->owner_user_id === $user->id;
        if (! $canAccessDomain) {
            return false;
        }

        if ($domain->cloudflare_enabled === false) {
            return false;
        }

        if (! $domain->isSubdomain()) {
            return true;
        }

        $domain->loadMissing('parentDomain:id,cloudflare_enabled');

        return $domain->parentDomain?->cloudflare_enabled !== false;
    }

    public function manageDb(User $user, Domain $domain): bool
    {
        return $user->isAdmin() || $domain->owner_user_id === $user->id;
    }
}
