<?php

use App\Http\Middleware\AddCspHeaders;
use App\Http\Middleware\AddEarlyHints;
use App\Http\Middleware\ApiTokenIpMiddleware;
use App\Http\Middleware\EnforceImpersonationTtl;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureMailFeature;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyOTP;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Trusted reverse proxies. Defaults to Docker bridge CIDRs so the panel
        // honours X-Forwarded-* from Caddy/frankenphp containers without
        // accepting spoofed headers from arbitrary upstreams. Override via
        // TRUSTED_PROXIES env (comma-separated CIDR list or "*" for all).
        $trustedProxies = env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');
        $middleware->trustProxies(
            at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', (string) $trustedProxies)),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'api.token.ip' => ApiTokenIpMiddleware::class,
            'idempotency' => IdempotencyKey::class,
            'mail.feature' => EnsureMailFeature::class,
        ]);

        $middleware->prependToGroup('web', AddCspHeaders::class);
        $middleware->appendToGroup('web', AddEarlyHints::class);
        $middleware->appendToGroup('web', SetLocale::class);
        $middleware->appendToGroup('web', HandleInertiaRequests::class);
        $middleware->appendToGroup('web', VerifyOTP::class);
        $middleware->appendToGroup('web', EnforceImpersonationTtl::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
