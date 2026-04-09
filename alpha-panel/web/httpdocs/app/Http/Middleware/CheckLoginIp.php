<?php

namespace App\Http\Middleware;

use App\Services\LoginSecurityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CheckLoginIp
{
    public function __construct(private LoginSecurityService $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->security->isIpAllowed($request->ip())) {
            throw ValidationException::withMessages([
                'login' => [__('Access denied from your IP address.')],
            ]);
        }

        return $next($request);
    }
}
