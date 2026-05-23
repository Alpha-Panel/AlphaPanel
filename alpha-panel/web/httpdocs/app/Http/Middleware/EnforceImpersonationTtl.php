<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceImpersonationTtl
{
    public function __construct(private ImpersonationService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->service->isActive()) {
            $startedAt = $this->service->startedAt();
            $maxMinutes = (int) config('impersonation.max_duration_minutes', 240);

            if ($startedAt !== null && $startedAt->addMinutes($maxMinutes)->isPast()) {
                $this->service->stop();
                $request->session()->flash('warning', __('Impersonation expired and was automatically ended.'));

                return $next($request);
            }

            // Pin impersonation session to the IP + user-agent recorded at start.
            // A stolen cookie used from a different network/device must not silently
            // continue the session.
            $session = $this->service->currentSession();
            if ($session !== null) {
                $currentUa = $request->userAgent() ? substr((string) $request->userAgent(), 0, 512) : null;
                $ipMismatch = $session->ip_address !== null && $session->ip_address !== $request->ip();
                $uaMismatch = $session->user_agent !== null && $session->user_agent !== $currentUa;

                if ($ipMismatch || $uaMismatch) {
                    $this->service->stop();
                    abort(403, __('Impersonation session terminated due to environment change.'));
                }
            }
        }

        return $next($request);
    }
}
