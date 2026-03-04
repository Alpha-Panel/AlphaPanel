<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class VerifyOTP
{
    /** @var list<string> */
    protected array $except = [
        '/webauthn/login/',
        '/webauthn/login/*',
        '/2fa-verify',
        '/otp-challenge',
        '/locale',
        '/lock-screen',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->inExceptArray($request)) {
            return $next($request);
        }

        if (Auth::guest()) {
            if (session()->has('otp')) {
                session()->remove('otp');
            }

            return $next($request);
        }

        if (! Auth::user()->otp) {
            return $next($request);
        }

        if (session()->has('otp') && session()->get('otp')) {
            return $next($request);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location(route('otp.challenge'));
        }

        return redirect()->route('otp.challenge');
    }

    protected function inExceptArray(Request $request): bool
    {
        foreach ($this->except as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->is($except)) {
                return true;
            }
        }

        return false;
    }
}
