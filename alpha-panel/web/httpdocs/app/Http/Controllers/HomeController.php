<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Models\Domain;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Services\CloudflareDnsService;
use App\Services\CrowdSecService;
use App\Services\GoogleDriveService;
use App\Services\HostMetricsService;
use App\Services\MysqlAdminService;
use App\Services\PortainerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    private const HOST_METRICS_CACHE_KEY = 'dashboard:host-metrics:v2';

    private const DOCKER_SERVICES_CACHE_KEY = 'dashboard:docker-services:v1';

    private const MYSQL_PROCESS_LIST_CACHE_KEY = 'dashboard:mysql-process-list:v1';

    private const CROWDSEC_SUMMARY_CACHE_KEY = 'dashboard:crowdsec-summary:v1';

    private const GOOGLE_DRIVE_SUMMARY_CACHE_KEY = 'dashboard:google-drive-summary:v1';

    private const HOST_METRICS_CACHE_SECONDS = 15;

    private const DOCKER_SERVICES_CACHE_SECONDS = 20;

    private const MYSQL_PROCESS_LIST_CACHE_SECONDS = 15;

    private const CROWDSEC_SUMMARY_CACHE_SECONDS = 20;

    private const GOOGLE_DRIVE_SUMMARY_CACHE_SECONDS = 120;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        // Pass only lightweight data on initial render.
        // Heavy admin metrics (host, docker, mysql, crowdsec) are loaded
        // asynchronously by the frontend via the dashboard.data endpoint.
        return Inertia::render('Dashboard', [
            'dashboard' => [
                'is_admin' => $user->isAdmin(),
                'stats' => $this->buildStats($user),
                'recent_domains' => $this->buildRecentDomains($user),
                'host_metrics' => null,
                'docker_services' => null,
                'mysql_monitor' => null,
                'crowdsec' => null,
                'google_drive' => null,
                'active_backup' => $user->isAdmin()
                    ? BackupRun::whereIn('status', ['uploading', 'running'])->latest('started_at')
                        ->first(['id', 'status', 'progress_percent', 'started_at'])
                    : null,
            ],
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
            'crowdsec' => $user->isAdmin() ? $this->buildCrowdSecSummary($useCache) : null,
            'google_drive' => $user->isAdmin() ? $this->buildGoogleDriveSummary($useCache) : null,
            'active_backup' => $user->isAdmin()
                ? BackupRun::where('status', 'uploading')->latest('started_at')
                    ->first(['id', 'status', 'progress_percent', 'started_at'])
                : null,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function buildStats(User $user): array
    {
        $domainQuery = Domain::query()
            ->when(! $user->isAdmin(), fn ($query) => $query->where('owner_user_id', $user->id));

        return [
            'total_domains' => (clone $domainQuery)->whereNull('parent_domain_id')->count(),
            'subdomains' => (clone $domainQuery)->whereNotNull('parent_domain_id')->count(),
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
                'type_label' => $domain->type->shortLabel(),
                'status' => $domain->status->value,
                'status_label' => $domain->status->label(),
                'php_version' => $domain->phpVersion?->slug,
                'created_ago' => $domain->created_at?->diffForHumans(short: true),
                'show_url' => route('domains.show', $domain),
                'cloudflare_enabled' => $domain->usesCloudflare(),
                'under_attack' => $underAttackMap[$domain->id] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Domain>  $domains
     * @return array<int, bool|null>
     */
    private function getUnderAttackStatuses($domains, CloudflareDnsService $cloudflare): array
    {
        $map = [];

        foreach ($domains as $domain) {
            if (! $domain->usesCloudflare()) {
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
                'uptime_seconds' => $data['uptime_seconds'],
                'load_1' => $data['load_1'],
                'load_5' => $data['load_5'],
                'load_15' => $data['load_15'],
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
                'uptime_seconds' => 0,
                'load_1' => 0,
                'load_5' => 0,
                'load_15' => 0,
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

    /**
     * @return array<string, mixed>
     */
    private function buildCrowdSecSummary(bool $useCache): array
    {
        try {
            /** @var CrowdSecService $crowdSec */
            $crowdSec = app(CrowdSecService::class);

            return $useCache
                ? Cache::remember(
                    self::CROWDSEC_SUMMARY_CACHE_KEY,
                    now()->addSeconds(self::CROWDSEC_SUMMARY_CACHE_SECONDS),
                    fn (): array => $crowdSec->getSummary(),
                )
                : $crowdSec->getSummary();
        } catch (\Throwable) {
            return [
                'configured' => false,
                'has_error' => true,
                'lapi_online' => false,
                'status_code' => null,
                'active_decisions' => 0,
                'recent_alerts_24h' => 0,
                'top_scenarios' => [],
                'last_sync_at' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildGoogleDriveSummary(bool $useCache): ?array
    {
        $settings = BackupSetting::instance();

        if (! $settings->isConnected()) {
            return null;
        }

        $summary = [
            'connected' => true,
            'connected_email' => $settings->connected_email,
            'last_backup_at' => $settings->last_backup_at?->diffForHumans(),
            'last_backup_at_formatted' => $settings->last_backup_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')),
            'quota_usage' => null,
            'quota_limit' => null,
        ];

        try {
            $quota = $useCache
                ? Cache::remember(
                    self::GOOGLE_DRIVE_SUMMARY_CACHE_KEY,
                    now()->addSeconds(self::GOOGLE_DRIVE_SUMMARY_CACHE_SECONDS),
                    fn (): array => app(GoogleDriveService::class)->getStorageQuota(),
                )
                : app(GoogleDriveService::class)->getStorageQuota();

            $summary['quota_usage'] = $quota['usage'] ?? null;
            $summary['quota_limit'] = $quota['limit'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Dashboard Google Drive quota fetch failed', [
                'exception' => $e,
            ]);
            $summary['quota_error'] = true;
        }

        return $summary;
    }

    private function clearDashboardMetricCache(): void
    {
        Cache::forget(self::HOST_METRICS_CACHE_KEY);
        Cache::forget(self::DOCKER_SERVICES_CACHE_KEY);
        Cache::forget(self::MYSQL_PROCESS_LIST_CACHE_KEY);
        Cache::forget(self::CROWDSEC_SUMMARY_CACHE_KEY);
        Cache::forget(self::GOOGLE_DRIVE_SUMMARY_CACHE_KEY);
    }
}
