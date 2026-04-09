<?php

namespace App\Http\Middleware;

use App\Models\SecuritySetting;
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
        '_debugbar*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if (! $this->isHtmlResponse($response) || $request->is(...$this->excludedPaths)) {
            return $response;
        }

        $debugbarActive = class_exists(\Barryvdh\Debugbar\Facades\Debugbar::class)
            && app()->bound('debugbar')
            && app('debugbar')->isEnabled();

        $captchaDomains = $this->getCaptchaCspDomains();

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-eval' 'nonce-{$nonce}'" . $captchaDomains['script'],
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https://www.gravatar.com",
            "connect-src 'self' wss:" . $captchaDomains['connect'],
            "frame-src 'self'" . $captchaDomains['frame'],
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
        ]);

        // Debugbar injects inline scripts without nonce — use Report-Only to avoid blocking
        $headerName = $debugbarActive
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($headerName, $csp);

        return $response;
    }

    /**
     * Get additional CSP domains for the active captcha provider.
     *
     * @return array{script: string, connect: string, frame: string}
     */
    private function getCaptchaCspDomains(): array
    {
        $empty = ['script' => '', 'connect' => '', 'frame' => ''];

        try {
            $provider = SecuritySetting::instance()->captcha_provider;
        } catch (\Throwable) {
            return $empty;
        }

        return match ($provider) {
            'turnstile' => [
                'script' => ' https://challenges.cloudflare.com',
                'connect' => ' https://challenges.cloudflare.com',
                'frame' => ' https://challenges.cloudflare.com',
            ],
            'recaptcha' => [
                'script' => ' https://www.google.com https://www.gstatic.com',
                'connect' => ' https://www.google.com',
                'frame' => ' https://www.google.com',
            ],
            default => $empty,
        };
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html');
    }
}
