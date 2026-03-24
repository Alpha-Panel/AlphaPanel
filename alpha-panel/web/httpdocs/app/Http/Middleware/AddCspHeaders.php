<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class AddCspHeaders
{
    /** Routes excluded from CSP enforcement (e.g. third-party dashboards). */
    private array $excludedPaths = [
        'telescope*',
        'horizon*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if (! $this->isHtmlResponse($response) || $request->is(...$this->excludedPaths)) {
            return $response;
        }

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-eval' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https://www.gravatar.com",
            "connect-src 'self' wss:",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html');
    }
}
