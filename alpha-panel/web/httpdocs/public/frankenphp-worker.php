<?php

/*
|--------------------------------------------------------------------------
| FrankenPHP worker with rolling restart
|--------------------------------------------------------------------------
|
| Drop-in replacement for the stub published by `octane:install`; copy it to
| public/frankenphp-worker.php of any Laravel Octane app served by FrankenPHP.
|
| Renewing storage/framework/worker.restart makes every worker thread leave
| its loop after the request it is serving. FrankenPHP then starts that thread
| again on the new code. Threads restart one by one: no admin API call, no
| restart of the PHP engine, no effect on other sites.
|
|   rm -f storage/framework/worker.restart && touch storage/framework/worker.restart
|
| Remove first: the file may have been created by another user (the panel
| writes it as root), and a plain `touch` on someone else's file is refused.
|
| The loop below mirrors vendor/laravel/octane/bin/frankenphp-worker.php.
|
*/

use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\FrankenPhp\FrankenPhpClient;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Stream;
use Laravel\Octane\Worker;
use Symfony\Component\HttpFoundation\Response;

if ((! ($_SERVER['FRANKENPHP_WORKER'] ?? false)) || ! function_exists('frankenphp_handle_request')) {
    echo 'FrankenPHP must be in worker mode to use this script.';

    exit(1);
}

ignore_user_abort(true);

$_SERVER['APP_BASE_PATH'] = $_ENV['APP_BASE_PATH'] ?? $_SERVER['APP_BASE_PATH'] ?? __DIR__.'/..';
$_SERVER['APP_PUBLIC_PATH'] = $_ENV['APP_PUBLIC_PATH'] ?? $_SERVER['APP_PUBLIC_PATH'] ?? __DIR__;

$basePath = require __DIR__.'/../vendor/laravel/octane/bin/bootstrap.php';

$restartSignal = $basePath.'/storage/framework/worker.restart';
$bootedAt = time();

// opcache keeps serving a file's old bytecode for revalidate_freq seconds after it
// last checked that file, so a thread restarted sooner than that would boot on
// stale code and never be restarted again. Wait the window out, plus one second
// because opcache compares whole seconds inclusively.
$opcacheWindow = (int) ini_get('opcache.revalidate_freq') + 1;

// ponytail: the signal is only checked after a request, so an idle thread answers
// one more request on the old code. Check before handling if that ever matters.
$restartIsDue = static function () use ($restartSignal, $bootedAt, $opcacheWindow): bool {
    clearstatcache(true, $restartSignal);

    $signaledAt = @filemtime($restartSignal);

    if ($signaledAt === false) {
        return false;
    }

    $freshFrom = $signaledAt + $opcacheWindow;

    return $bootedAt < $freshFrom && time() >= $freshFrom;
};

$frankenPhpClient = new FrankenPhpClient;

$worker = tap(new Worker(
    new ApplicationFactory($basePath), $frankenPhpClient
))->boot();

$requestCount = 0;
$debugMode = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? 'false';
$maxRequests = $_ENV['MAX_REQUESTS'] ?? $_SERVER['MAX_REQUESTS'] ?? 1000;
$requestMaxExecutionTime = $_ENV['REQUEST_MAX_EXECUTION_TIME'] ?? $_SERVER['REQUEST_MAX_EXECUTION_TIME'] ?? null;

if (PHP_OS_FAMILY === 'Linux' && ! is_null($requestMaxExecutionTime)) {
    set_time_limit((int) $requestMaxExecutionTime);
}

try {
    $handleRequest = static function () use ($worker, $frankenPhpClient, $debugMode) {
        try {
            [$request, $context] = $frankenPhpClient->marshalRequest(new RequestContext);

            $worker->handle($request, $context);
        } catch (Throwable $e) {
            report($e);

            $response = new Response(
                $debugMode === 'true' ? (string) $e : 'Internal Server Error',
                500,
                [
                    'Status' => '500 Internal Server Error',
                    'Content-Type' => 'text/plain',
                ],
            );

            $response->send();

            Stream::shutdown($e);
        }
    };

    while ($requestCount < $maxRequests && frankenphp_handle_request($handleRequest)) {
        $requestCount++;

        if ($restartIsDue()) {
            break;
        }
    }
} finally {
    $worker->terminate();

    gc_collect_cycles();
}
