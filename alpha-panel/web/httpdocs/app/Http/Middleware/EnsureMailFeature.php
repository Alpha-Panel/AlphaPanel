<?php

namespace App\Http\Middleware;

use App\Services\Mail\MailSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMailFeature
{
    public function __construct(private readonly MailSettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->mailEnabled()) {
            abort(404);
        }

        return $next($request);
    }
}
