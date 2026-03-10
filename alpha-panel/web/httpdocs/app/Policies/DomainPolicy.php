<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;

class DomainPolicy
{
    /**
     * Check whether the user can access the given domain (owner or authorized via pivot).
     * Gate::before already handles admin bypass, so no isAdmin() check needed here.
     */
    private function canAccessDomain(User $user, Domain $domain): bool
    {
        return $domain->owner_user_id === $user->id
            || $domain->authorizedUsers()->where('user_id', $user->id)->exists();
    }

    // ── General ──────────────────────────────────────────────

    public function viewAny(User $user): bool
    {
        return $user->can('domain.view');
    }

    public function view(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.view');
    }

    public function create(User $user): bool
    {
        return $user->can('panel.domains.create');
    }

    public function update(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.edit');
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('panel.domains.delete');
    }

    public function provision(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.provision');
    }

    // ── FTP ──────────────────────────────────────────────────

    public function manageFtp(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.ftp.manage');
    }

    // ── SSL ──────────────────────────────────────────────────

    public function manageSsl(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.ssl.manage');
    }

    // ── DNS ──────────────────────────────────────────────────

    public function viewDns(User $user, Domain $domain): bool
    {
        if (! $this->canAccessDomain($user, $domain) || ! $user->can('domain.dns.view')) {
            return false;
        }

        return $this->domainHasCloudflare($domain);
    }

    public function manageDns(User $user, Domain $domain): bool
    {
        if (! $this->canAccessDomain($user, $domain) || ! $user->can('domain.dns.manage')) {
            return false;
        }

        return $this->domainHasCloudflare($domain);
    }

    // ── Cloudflare ───────────────────────────────────────────

    public function viewCloudflare(User $user, Domain $domain): bool
    {
        if (! $this->canAccessDomain($user, $domain) || ! $user->can('domain.cloudflare.view')) {
            return false;
        }

        return $this->domainHasCloudflare($domain);
    }

    public function manageCloudflare(User $user, Domain $domain): bool
    {
        if (! $this->canAccessDomain($user, $domain) || ! $user->can('domain.cloudflare.manage')) {
            return false;
        }

        return $this->domainHasCloudflare($domain);
    }

    // ── Databases ────────────────────────────────────────────

    public function viewDb(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.databases.view');
    }

    public function manageDb(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.databases.manage');
    }

    // ── PHP Settings ─────────────────────────────────────────

    public function managePhp(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.php.manage');
    }

    // ── Supervisor ───────────────────────────────────────────

    public function viewSupervisor(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.supervisor.view');
    }

    public function manageSupervisor(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.supervisor.manage');
    }

    public function runArtisan(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.supervisor.artisan');
    }

    // ── Cron Jobs ────────────────────────────────────────────

    public function viewCronJobs(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.cron-jobs.view');
    }

    public function manageCronJobs(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.cron-jobs.manage');
    }

    // ── Package Manager ──────────────────────────────────────

    public function viewPackages(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.packages.view');
    }

    public function managePackages(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.packages.manage');
    }

    // ── Files ────────────────────────────────────────────────

    public function viewFiles(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.files.view');
    }

    public function manageFiles(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.files.manage');
    }

    // ── Logs ─────────────────────────────────────────────────

    public function viewLogs(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.logs.view');
    }

    // ── ModSecurity ──────────────────────────────────────────

    public function viewModSecurity(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.modsecurity.view');
    }

    public function manageModSecurity(User $user, Domain $domain): bool
    {
        return $this->canAccessDomain($user, $domain) && $user->can('domain.modsecurity.manage');
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * Check that the domain (or its parent) has Cloudflare enabled.
     */
    private function domainHasCloudflare(Domain $domain): bool
    {
        if ($domain->cloudflare_enabled === false) {
            return false;
        }

        if (! $domain->isSubdomain()) {
            return true;
        }

        $domain->loadMissing('parentDomain:id,cloudflare_enabled');

        return $domain->parentDomain?->cloudflare_enabled !== false;
    }
}
