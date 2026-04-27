<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ImpersonationService
{
    private const SESSION_KEY_IMPERSONATOR_ID = 'impersonation.impersonator_id';

    private const SESSION_KEY_SESSION_ID = 'impersonation.session_id';

    private const SESSION_KEY_STARTED_AT = 'impersonation.started_at';

    public function start(User $target): ImpersonationSession
    {
        $actor = Auth::user();
        if ($actor === null) {
            throw new AuthenticationException;
        }

        $this->authorizeStart($actor, $target);

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'impersonation.start',
            'summary' => "Started impersonating {$target->username}",
        ]);

        $request = request();

        $session = ImpersonationSession::create([
            'impersonator_id' => $actor->id,
            'target_id' => $target->id,
            'session_token' => (string) Str::uuid(),
            'started_at' => now(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? substr((string) $request->userAgent(), 0, 512) : null,
        ]);

        Auth::loginUsingId($target->id);
        session()->regenerate();

        session([
            self::SESSION_KEY_IMPERSONATOR_ID => $actor->id,
            self::SESSION_KEY_SESSION_ID => $session->id,
            self::SESSION_KEY_STARTED_AT => now()->toIso8601String(),
        ]);

        return $session;
    }

    public function stop(): void
    {
        if (! $this->isActive()) {
            return;
        }

        $impersonatorId = (int) session(self::SESSION_KEY_IMPERSONATOR_ID);
        $sessionId = (int) session(self::SESSION_KEY_SESSION_ID);
        $target = Auth::user();

        ImpersonationSession::where('id', $sessionId)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        session()->forget([
            self::SESSION_KEY_IMPERSONATOR_ID,
            self::SESSION_KEY_SESSION_ID,
            self::SESSION_KEY_STARTED_AT,
        ]);

        Auth::loginUsingId($impersonatorId);
        session()->regenerate();

        AuditLog::create([
            'user_id' => $impersonatorId,
            'action' => 'impersonation.stop',
            'summary' => $target ? "Stopped impersonating {$target->username}" : 'Stopped impersonating',
        ]);
    }

    public function isActive(): bool
    {
        return session()->has(self::SESSION_KEY_IMPERSONATOR_ID);
    }

    public function impersonator(): ?User
    {
        if (! $this->isActive()) {
            return null;
        }

        return User::find(session(self::SESSION_KEY_IMPERSONATOR_ID));
    }

    public function target(): ?User
    {
        return $this->isActive() ? Auth::user() : null;
    }

    public function currentSession(): ?ImpersonationSession
    {
        if (! $this->isActive()) {
            return null;
        }

        return ImpersonationSession::find(session(self::SESSION_KEY_SESSION_ID));
    }

    public function startedAt(): ?CarbonImmutable
    {
        $iso = session(self::SESSION_KEY_STARTED_AT);

        return $iso ? CarbonImmutable::parse($iso) : null;
    }

    public function canImpersonate(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        if ($this->isActive()) {
            return false;
        }

        $isSuperAdmin = $actor->isAdmin();

        if (! $isSuperAdmin && ! $actor->can('panel.users.impersonate')) {
            return false;
        }

        if ($target->isAdmin() && ! $isSuperAdmin) {
            return false;
        }

        return true;
    }

    private function authorizeStart(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new AuthorizationException(__('You cannot impersonate yourself.'));
        }

        if ($this->isActive()) {
            throw new AuthorizationException(__('You are already impersonating another user.'));
        }

        $isSuperAdmin = $actor->isAdmin();
        $hasPermission = $actor->can('panel.users.impersonate');

        if (! $isSuperAdmin && ! $hasPermission) {
            throw new AuthorizationException(__('You do not have permission to impersonate this user.'));
        }

        if ($target->isAdmin() && ! $isSuperAdmin) {
            throw new AuthorizationException(__('Only super admins can impersonate other admins.'));
        }
    }
}
