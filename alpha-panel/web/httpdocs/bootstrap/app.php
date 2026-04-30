<?php

use App\Http\Middleware\AddCspHeaders;
use App\Http\Middleware\AddEarlyHints;
use App\Http\Middleware\ApiTokenIpMiddleware;
use App\Http\Middleware\EnforceImpersonationTtl;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyOTP;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'api.token.ip' => ApiTokenIpMiddleware::class,
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
