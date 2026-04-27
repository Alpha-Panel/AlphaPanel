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
            }
        }

        return $next($request);
    }
}
