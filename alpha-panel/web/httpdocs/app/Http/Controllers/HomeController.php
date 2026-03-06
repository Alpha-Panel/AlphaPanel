<?php

namespace App\Http\Controllers;

use App\Enums\DomainStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Services\CloudflareDnsService;
use App\Services\HostMetricsService;
use App\Services\MysqlAdminService;
use App\Services\PortainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    private const HOST_METRICS_CACHE_KEY = 'dashboard:host-metrics:v1';

    private const DOCKER_SERVICES_CACHE_KEY = 'dashboard:docker-services:v1';

    private const MYSQL_PROCESS_LIST_CACHE_KEY = 'dashboard:mysql-process-list:v1';

    private const HOST_METRICS_CACHE_SECONDS = 15;

    private const DOCKER_SERVICES_CACHE_SECONDS = 20;

    private const MYSQL_PROCESS_LIST_CACHE_SECONDS = 15;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'dashboard' => $this->buildDashboardPayload($user, false),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(
            $this->buildDashboardPayload($user, $request->boolean('show_sleeping')),
        );
    }

    public function dockerAction(Request $request, PortainerService $portainer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->isAdmin(), 403);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:start,stop,restart'],
            'container_id' => ['required', 'string', 'max:128'],
            'container_name' => ['nullable', 'string', 'max:255'],
            'show_sleeping' => ['nullable', 'boolean'],
        ]);

        $containerId = $validated['container_id'];
        $containerName = $validated['container_name'] ?? $containerId;
        $action = $validated['action'];

        try {
            $result = match ($action) {
                'start' => $portainer->startContainer($containerId),
                'stop' => $portainer->stopContainer($containerId),
                'restart' => $portainer->restartContainer($containerId),
                default => false,
            };
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => "{$containerName} — {$exception->getMessage()}",
                'dashboard' => $this->buildDashboardPayload($user, (bool) ($validated['show_sleeping'] ?? false), false),
            ], 422);
        }

        if ($result) {
            $this->clearDashboardMetricCache();
        }

        $label = match ($action) {
            'start' => __('started'),
            'stop' => __('stopped'),
            'restart' => __('restarted'),
            default => $action,
        };

        AuditLog::create([
            'user_id' => $user->id,
            'action' => "docker_{$action}",
            'summary' => $result
                ? "{$containerName} {$label}"
                : "{$containerName} — action failed",
        ]);

        return response()->json([
            'success' => $result,
            'message' => $result
                ? "{$containerName} {$label}."
                : "{$containerName} — ".__('action failed.'),
            'dashboard' => $this->buildDashboardPayload($user, (bool) ($validated['show_sleeping'] ?? false), false),
        ], $result ? 200 : 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardPayload(User $user, bool $showSleeping, bool $useCache = true): array
    {
        return [
            'is_admin' => $user->isAdmin(),
            'stats' => $this->buildStats($user),
            'recent_domains' => $this->buildRecentDomains($user),
            'host_metrics' => $user->isAdmin() ? $this->buildHostMetrics($useCache) : null,
            'docker_services' => $user->isAdmin() ? $this->buildDockerServices($useCache) : null,
            'mysql_monitor' => $user->isAdmin() ? $this->buildMysqlMonitor($showSleeping, $useCache) : null,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function buildStats(User $user): array
    {
        $parentDomainQuery = Domain::query()
            ->whereNull('parent_domain_id')
            ->when(! $user->isAdmin(), fn ($query) => $query->where('owner_user_id', $user->id));

        $subdomainQuery = Domain::query()
            ->whereNotNull('parent_domain_id')
            ->when(! $user->isAdmin(), fn ($query) => $query->where('owner_user_id', $user->id));

        return [
            'total_domains' => (clone $parentDomainQuery)->count(),
            'active_domains' => (clone $parentDomainQuery)->where('status', DomainStatus::Active)->count(),
            'subdomains' => $subdomainQuery->count(),
            'total_databases' => ManagedDatabase::count(),
            'total_users' => User::count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentDomains(User $user): array
    {
        $domains = Domain::query()
            ->with('phpVersion')
            ->whereNull('parent_domain_id')
            ->when(! $user->isAdmin(), fn ($query) => $query->where('owner_user_id', $user->id))
            ->latest()
            ->limit(8)
            ->get();

        $cloudflare = app(CloudflareDnsService::class);
        $underAttackMap = $this->getUnderAttackStatuses($domains, $cloudflare);

        return $domains
            ->map(fn (Domain $domain): array => [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
                'type' => $domain->type->value,
                'type_label' => $domain->type->label(),
                'status' => $domain->status->value,
                'status_label' => $domain->status->label(),
                'php_version' => $domain->phpVersion?->slug,
                'created_ago' => $domain->created_at?->diffForHumans(short: true),
                'show_url' => route('domains.show', $domain),
                'cloudflare_enabled' => (bool) $domain->cloudflare_enabled,
                'under_attack' => $underAttackMap[$domain->id] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Domain>  $domains
     * @return array<int, bool|null>
     */
    private function getUnderAttackStatuses($domains, CloudflareDnsService $cloudflare): array
    {
        $map = [];

        foreach ($domains as $domain) {
            if (! $domain->cloudflare_enabled) {
                $map[$domain->id] = null;

                continue;
            }

            $cacheKey = "dashboard:under-attack:{$domain->id}";

            $map[$domain->id] = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($domain, $cloudflare): ?bool {
                try {
                    $zoneSummary = $cloudflare->getZoneSummary($domain->fqdn);

                    if (! ($zoneSummary['exists'] ?? false) || ! is_string($zoneSummary['zone_id'] ?? null)) {
                        return null;
                    }

                    $setting = $cloudflare->getZoneSetting($zoneSummary['zone_id'], 'security_level');

                    return ($setting['value'] ?? null) === 'under_attack';
                } catch (\Throwable) {
                    return null;
                }
            });
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHostMetrics(bool $useCache): array
    {
        try {
            /** @var HostMetricsService $metrics */
            $metrics = app(HostMetricsService::class);
            $data = $useCache
                ? Cache::remember(self::HOST_METRICS_CACHE_KEY, now()->addSeconds(self::HOST_METRICS_CACHE_SECONDS), fn (): array => $metrics->getHostMetrics())
                : $metrics->getHostMetrics();

            return [
                'has_error' => false,
                'cpu_percent' => $data['cpu_percent'],
                'mem_used_mb' => $data['mem_used_mb'],
                'mem_total_mb' => $data['mem_total_mb'],
                'mem_percent' => $data['mem_percent'],
                'disk_used_gb' => $data['disk_used_gb'],
                'disk_total_gb' => $data['disk_total_gb'],
                'disk_percent' => $data['disk_percent'],
            ];
        } catch (\Throwable) {
            return [
                'has_error' => true,
                'cpu_percent' => 0,
                'mem_used_mb' => 0,
                'mem_total_mb' => 0,
                'mem_percent' => 0,
                'disk_used_gb' => 0,
                'disk_total_gb' => 0,
                'disk_percent' => 0,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDockerServices(bool $useCache): array
    {
        try {
            /** @var HostMetricsService $metrics */
            $metrics = app(HostMetricsService::class);
            $containers = $useCache
                ? Cache::remember(self::DOCKER_SERVICES_CACHE_KEY, now()->addSeconds(self::DOCKER_SERVICES_CACHE_SECONDS), fn (): array => $metrics->getContainerStats())
                : $metrics->getContainerStats();

            return [
                'has_error' => false,
                'containers' => $containers,
            ];
        } catch (\Throwable) {
            return [
                'has_error' => true,
                'containers' => [],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMysqlMonitor(bool $showSleeping, bool $useCache): array
    {
        try {
            /** @var MysqlAdminService $mysql */
            $mysql = app(MysqlAdminService::class);

            $allProcesses = $useCache
                ? Cache::remember(
                    self::MYSQL_PROCESS_LIST_CACHE_KEY,
                    now()->addSeconds(self::MYSQL_PROCESS_LIST_CACHE_SECONDS),
                    fn (): array => $mysql->getProcessList(),
                )
                : $mysql->getProcessList();
            $filteredProcesses = collect($allProcesses)
                ->when(! $showSleeping, fn ($collection) => $collection->reject(fn ($process) => ($process['Command'] ?? '') === 'Sleep'))
                ->sortByDesc(fn ($process) => (int) ($process['Time'] ?? 0))
                ->take(20)
                ->map(fn ($process): array => [
                    'id' => (int) ($process['Id'] ?? 0),
                    'user' => (string) ($process['User'] ?? '-'),
                    'database' => (string) ($process['db'] ?? '-'),
                    'time' => (int) ($process['Time'] ?? 0),
                    'command' => (string) ($process['Command'] ?? '-'),
                    'info' => (string) ($process['Info'] ?? ''),
                ])
                ->values()
                ->all();

            return [
                'has_error' => false,
                'show_sleeping' => $showSleeping,
                'total_connections' => count($allProcesses),
                'processes' => $filteredProcesses,
            ];
        } catch (\Throwable) {
            return [
                'has_error' => true,
                'show_sleeping' => $showSleeping,
                'total_connections' => 0,
                'processes' => [],
            ];
        }
    }

    private function clearDashboardMetricCache(): void
    {
        Cache::forget(self::HOST_METRICS_CACHE_KEY);
        Cache::forget(self::DOCKER_SERVICES_CACHE_KEY);
        Cache::forget(self::MYSQL_PROCESS_LIST_CACHE_KEY);
    }
}
