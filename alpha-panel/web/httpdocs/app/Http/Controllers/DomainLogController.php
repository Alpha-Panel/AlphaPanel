<?php

namespace App\Http\Controllers;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Services\DomainRequestLogService;
use App\Services\PortainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DomainLogController extends Controller
{
    private const TERMINAL_WS_CACHE_PREFIX = 'terminal:ws:';

    public function index(Domain $domain): Response
    {
        $this->authorize('view', $domain);

        return Inertia::render('Domains/Logs', [
            'domain' => $domain,
        ]);
    }

    public function entries(Request $request, Domain $domain, DomainRequestLogService $logService): JsonResponse
    {
        $this->authorize('view', $domain);

        $entries = $logService->getDomainEntries($domain, [
            'q' => $request->string('q')->toString(),
            'ip' => $request->string('ip')->toString(),
            'before' => $request->string('before')->toString(),
            'since' => $request->string('since')->toString(),
            'limit' => $request->integer('limit', 500),
        ]);

        return response()->json([
            'entries' => $entries,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function streamStart(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        $this->authorize('view', $domain);

        $request->validate([
            'since' => 'nullable|string|max:100',
        ]);

        [$containerName, $logPaths] = $this->resolveStreamTarget($domain);
        $container = $portainer->findContainerByName($containerName);
        $containerId = (string) ($container['Id'] ?? '');

        if ($containerId === '') {
            return response()->json([
                'message' => __('Log container could not be resolved.'),
            ], 422);
        }

        $sessionId = Str::uuid()->toString();
        $wsToken = Str::random(40);
        $since = trim($request->string('since')->toString());
        $safePaths = array_map(
            static fn (string $path): string => "'".str_replace("'", "'\\''", $path)."'",
            $logPaths,
        );
        $tailCommand = 'tail -n 0 -F '.implode(' ', $safePaths).' 2>/dev/null';

        $exec = $portainer->createInteractiveExec($containerId, [
            '/bin/sh',
            '-lc',
            $tailCommand,
        ]);
        $execId = (string) ($exec['Id'] ?? '');

        if ($execId === '') {
            return response()->json([
                'message' => __('Log stream could not be started.'),
            ], 500);
        }

        Cache::put(self::TERMINAL_WS_CACHE_PREFIX.$wsToken, [
            'session_id' => $sessionId,
            'exec_id' => $execId,
            'ws_url' => $portainer->getExecWebSocketUrl($execId),
            'api_key' => $portainer->getExecWebSocketHeaders()['X-API-Key'],
            'container_name' => "domain-logs:{$domain->fqdn}",
            'user_id' => $request->user()->id,
            'ip_address' => $request->ip(),
            'port' => $request->server('REMOTE_PORT'),
            'stream' => [
                'type' => 'domain_logs',
                'domain_id' => $domain->id,
                'since' => $since,
                'paths' => $logPaths,
            ],
        ], now()->addSeconds(30));

        return response()->json([
            'session_id' => $sessionId,
            'ws_token' => $wsToken,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function resolveStreamTarget(Domain $domain): array
    {
        if ($domain->type === DomainType::ApacheReverseProxy) {
            $logsPath = $domain->getBasePath().'/logs';

            return [
                (string) config('panel.php_code_server_container', 'php-code-server'),
                [
                    $logsPath.'/access.log',
                    $logsPath.'/error.log',
                ],
            ];
        }

        return [
            (string) config('panel.frankenphp_container', 'frankenphp'),
            [
                '/var/log/caddy/'.$domain->fqdn.'.log',
            ],
        ];
    }
}
