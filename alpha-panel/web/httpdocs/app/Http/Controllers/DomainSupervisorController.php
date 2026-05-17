<?php

namespace App\Http\Controllers;

use App\Enums\DomainType;
use App\Enums\SupervisorType;
use App\Http\Requests\RunArtisanCommandRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainSupervisor;
use App\Services\DomainConfigService;
use App\Services\LaravelPackageDetector;
use App\Services\PortainerService;
use App\Services\ReloadService;
use App\Services\ReverbPortAllocator;
use App\Services\SiteEnvService;
use App\Services\SsrPortAllocator;
use App\Services\SupervisorConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DomainSupervisorController extends Controller
{
    private const MAX_OUTPUT_LENGTH = 50000;

    public function index(Domain $domain, LaravelPackageDetector $packageDetector): Response
    {
        $this->authorize('viewSupervisor', $domain);

        $isLaravel = $packageDetector->isLaravel($domain);

        $supervisors = $domain->supervisors()
            ->get()
            ->keyBy(fn (DomainSupervisor $s): string => $s->type->value);

        $requirements = $packageDetector->checkSupervisorRequirements($domain);
        $processes = [];

        foreach (SupervisorType::cases() as $type) {
            $existing = $supervisors->get($type->value);
            $req = $requirements[$type->value] ?? ['available' => true, 'package' => null];

            // If not a Laravel app, nothing is available
            $available = $isLaravel && $req['available'];
            $missingPackage = ! $isLaravel ? 'laravel/framework' : ($req['available'] ? null : $req['package']);

            $row = [
                'type' => $type->value,
                'label' => $type->label(),
                'enabled' => $existing?->enabled ?? false,
                'num_procs' => $existing?->num_procs ?? ($type === SupervisorType::Queue ? 3 : 1),
                'queue_names' => $type === SupervisorType::Queue ? ($existing?->queue_names ?? '') : null,
                'supports_num_procs' => $type->supportsNumProcs(),
                'is_available' => $available,
                'missing_package' => $missingPackage,
            ];

            if ($type === SupervisorType::Reverb) {
                $row['reverb_port'] = $existing?->reverb_port;
                $row['reverb_app_id'] = $existing?->reverb_app_id;
                $row['reverb_app_key'] = $existing?->reverb_app_key;
                $row['reverb_app_secret'] = $existing?->reverb_app_secret;
            }

            if ($type === SupervisorType::Ssr) {
                $row['ssr_port'] = $existing?->ssr_port;
            }

            $processes[] = $row;
        }

        return Inertia::render('Domains/Supervisors', [
            'domain' => [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
            ],
            'processes' => $processes,
            'is_laravel' => $isLaravel,
        ]);
    }

    public function update(Request $request, Domain $domain, SupervisorConfigService $configService, LaravelPackageDetector $packageDetector): JsonResponse
    {
        $this->authorize('manageSupervisor', $domain);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_column(SupervisorType::cases(), 'value'))],
            'enabled' => ['required', 'boolean'],
            'num_procs' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'queue_names' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:/^[a-zA-Z0-9_,\-]*$/'],
        ]);

        $type = SupervisorType::from($validated['type']);

        // Verify Laravel and required packages are installed before enabling
        if ($validated['enabled']) {
            if (! $packageDetector->isLaravel($domain)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('This process requires the :package package. Please install it first.', [
                        'package' => 'laravel/framework',
                    ]),
                ], 422);
            }

            $requirements = $packageDetector->checkSupervisorRequirements($domain);
            $req = $requirements[$type->value] ?? ['available' => true, 'package' => null];

            if (! $req['available']) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('This process requires the :package package. Please install it first.', [
                        'package' => $req['package'],
                    ]),
                ], 422);
            }
        }

        $updateData = [
            'enabled' => $validated['enabled'],
            'num_procs' => $type->supportsNumProcs() ? ($validated['num_procs'] ?? 1) : 1,
        ];

        if ($type === SupervisorType::Queue && array_key_exists('queue_names', $validated)) {
            $updateData['queue_names'] = $validated['queue_names'] ?: null;
        }

        $supervisor = DomainSupervisor::updateOrCreate(
            ['domain_id' => $domain->id, 'type' => $type],
            $updateData,
        );

        try {
            if ($type === SupervisorType::Reverb && $validated['enabled']) {
                $this->provisionReverb($domain, $supervisor);
            }

            if ($type === SupervisorType::Ssr && $validated['enabled']) {
                $this->provisionSsr($domain, $supervisor);
            }

            $configService->syncSingle($supervisor);

            $action = $validated['enabled'] ? 'supervisor_enabled' : 'supervisor_disabled';
            $summary = $type->label().($type->supportsNumProcs() ? " (workers: {$supervisor->num_procs})" : '');

            $this->createAuditLog($request, $domain, $action, $summary);

            return response()->json([
                'status' => 'success',
                'message' => $validated['enabled']
                    ? __(':process enabled successfully.', ['process' => $type->label()])
                    : __(':process disabled successfully.', ['process' => $type->label()]),
                'enabled' => $supervisor->enabled,
                'num_procs' => $supervisor->num_procs,
                'queue_names' => $type === SupervisorType::Queue ? ($supervisor->queue_names ?? '') : null,
                'reverb' => $type === SupervisorType::Reverb ? [
                    'port' => $supervisor->reverb_port,
                    'app_id' => $supervisor->reverb_app_id,
                    'app_key' => $supervisor->reverb_app_key,
                    'app_secret' => $supervisor->reverb_app_secret,
                ] : null,
                'ssr' => $type === SupervisorType::Ssr ? [
                    'port' => $supervisor->ssr_port,
                ] : null,
            ]);
        } catch (\Throwable $e) {
            Log::error("Supervisor update failed for {$domain->fqdn}/{$type->value}: {$e->getMessage()}");

            $this->createAuditLog(
                $request,
                $domain,
                'supervisor_update_failed',
                "{$type->label()}: {$e->getMessage()}",
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to update supervisor configuration: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    public function restart(Request $request, Domain $domain, SupervisorConfigService $configService): JsonResponse
    {
        $this->authorize('manageSupervisor', $domain);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_column(SupervisorType::cases(), 'value'))],
        ]);

        $type = SupervisorType::from($validated['type']);

        try {
            $configService->restartProcess($domain, $type);

            $this->createAuditLog(
                $request,
                $domain,
                'supervisor_restarted',
                "{$type->label()} restart signal sent.",
            );

            return response()->json([
                'status' => 'success',
                'message' => __(':process restart signal sent.', ['process' => $type->label()]),
            ]);
        } catch (\Throwable $exception) {
            Log::error("Supervisor restart failed for {$domain->fqdn}/{$type->value}: {$exception->getMessage()}");

            $this->createAuditLog(
                $request,
                $domain,
                'supervisor_restart_failed',
                "{$type->label()}: {$exception->getMessage()}",
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to restart :process: :error', [
                    'process' => $type->label(),
                    'error' => $exception->getMessage(),
                ]),
            ], 500);
        }
    }

    public function restartFrankenphpWorkers(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        $this->authorize('manageSupervisor', $domain);

        try {
            $result = $portainer->execInContainer(
                'frankenphp',
                ['sh', '-c', 'curl -sf -X POST http://localhost:2019/frankenphp/workers/restart'],
                10,
            );

            if (! $result->isSuccessful()) {
                $error = trim($result->errorOutput) !== '' ? trim($result->errorOutput) : trim($result->output);

                throw new \RuntimeException($error !== '' ? $error : 'Worker restart command failed.');
            }

            $this->createAuditLog(
                $request,
                $domain,
                'frankenphp_workers_restart_signaled',
                'FrankenPHP workers restart signal sent.',
            );

            return response()->json([
                'status' => 'success',
                'message' => __('FrankenPHP workers restart signal sent.'),
            ]);
        } catch (\Throwable $exception) {
            Log::error("FrankenPHP workers restart signal failed for {$domain->fqdn}: {$exception->getMessage()}");

            $this->createAuditLog(
                $request,
                $domain,
                'frankenphp_workers_restart_failed',
                $exception->getMessage(),
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to send FrankenPHP workers restart signal: :error', [
                    'error' => $exception->getMessage(),
                ]),
            ], 500);
        }
    }

    public function runOptimize(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        $this->authorize('manageSupervisor', $domain);

        $container = $domain->type === DomainType::ApacheReverseProxy
            ? 'php-code-server'
            : 'frankenphp';

        try {
            $result = $portainer->execInContainer(
                $container,
                ['sh', '-lc', $this->buildAppCommandScript($domain, 'php artisan optimize:clear && php artisan optimize')],
                300,
            );

            if (! $result->isSuccessful()) {
                $error = trim($result->errorOutput) !== '' ? trim($result->errorOutput) : trim($result->output);
                if ($error === '') {
                    $error = 'Unknown error.';
                }

                throw new \RuntimeException($error);
            }

            $this->createAuditLog(
                $request,
                $domain,
                'laravel_optimize_refreshed',
                'Laravel optimize cache cleared and rebuilt.',
            );

            return response()->json([
                'status' => 'success',
                'message' => __('Laravel optimize cache cleared and rebuilt successfully.'),
            ]);
        } catch (\Throwable $exception) {
            Log::error("Laravel optimize refresh failed for {$domain->fqdn}: {$exception->getMessage()}");

            $this->createAuditLog(
                $request,
                $domain,
                'laravel_optimize_refresh_failed',
                $exception->getMessage(),
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to run Laravel optimize commands: :error', [
                    'error' => $exception->getMessage(),
                ]),
            ], 500);
        }
    }

    public function runArtisan(RunArtisanCommandRequest $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        $this->authorize('runArtisan', $domain);

        $domain->loadMissing('ftpUser');

        $raw = trim($request->validated()['command']);

        // Parse and rebuild command with escaped arguments to prevent injection
        $normalized = str_starts_with($raw, 'artisan ') ? 'php '.$raw : $raw;
        $parts = preg_split('/\s+/', $normalized);
        // $parts[0] = 'php', $parts[1] = 'artisan', $parts[2] = subcommand, rest = args
        $command = 'php artisan '.($parts[2] ?? '');
        for ($i = 3, $count = count($parts); $i < $count; $i++) {
            $command .= ' '.escapeshellarg($parts[$i]);
        }

        $container = $domain->type === DomainType::ApacheReverseProxy
            ? 'php-code-server'
            : 'frankenphp';

        $execUser = $domain->ftpUser?->username;

        try {
            $result = $portainer->execInContainer(
                $container,
                ['sh', '-lc', $this->buildAppCommandScript($domain, $command)],
                300,
                $execUser,
            );

            $output = trim($result->output);
            if (trim($result->errorOutput) !== '') {
                $output .= "\n--- STDERR ---\n".trim($result->errorOutput);
            }

            $output = mb_substr($output, 0, self::MAX_OUTPUT_LENGTH);

            $this->createAuditLog(
                $request,
                $domain,
                'artisan_command_executed',
                $command,
                $output !== '' ? $output : null,
            );

            return response()->json([
                'status' => $result->isSuccessful() ? 'success' : 'error',
                'message' => $result->isSuccessful()
                    ? __('Command executed successfully.')
                    : __('Command finished with errors.'),
                'output' => $output,
                'exit_code' => $result->exitCode,
            ]);
        } catch (\Throwable $exception) {
            Log::error("Artisan command failed for {$domain->fqdn}: {$exception->getMessage()}");

            $this->createAuditLog(
                $request,
                $domain,
                'artisan_command_failed',
                "{$command}: {$exception->getMessage()}",
            );

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to execute artisan command: :error', [
                    'error' => $exception->getMessage(),
                ]),
                'output' => '',
                'exit_code' => -1,
            ], 500);
        }
    }

    private function buildAppCommandScript(Domain $domain, string $command): string
    {
        $appDir = escapeshellarg('/var/www/vhosts/'.$domain->getApexDomain().'/httpdocs');

        return <<<SH
export COLUMNS=220
cd {$appDir}
{$command}
SH;
    }

    /**
     * Allocate a per-site Reverb port, mint credentials if missing, write the
     * hosted site's .env, and regenerate + reload the Caddyfile so the new
     * /app/* proxy block is live before the supervisor starts the process.
     */
    /**
     * Allocate a per-site SSR port and write INERTIA_SSR_* env vars to the hosted site's .env.
     */
    private function provisionSsr(Domain $domain, DomainSupervisor $supervisor): void
    {
        app(SsrPortAllocator::class)->allocate($supervisor);
        app(SiteEnvService::class)->setSsrEnv($domain, $supervisor);
    }

    private function provisionReverb(Domain $domain, DomainSupervisor $supervisor): void
    {
        app(ReverbPortAllocator::class)->allocate($supervisor);

        if ($supervisor->reverb_app_id === null) {
            $supervisor->forceFill([
                'reverb_app_id' => (string) Str::random(16),
                'reverb_app_key' => (string) Str::random(32),
                'reverb_app_secret' => (string) Str::random(40),
            ])->save();
        }

        app(SiteEnvService::class)->setReverbEnv($domain, $supervisor);
        app(DomainConfigService::class)->regenerateCaddyConfig($domain);
        app(ReloadService::class)->reloadCaddy();
    }

    private function createAuditLog(Request $request, Domain $domain, string $action, string $summary, ?string $details = null): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'domain_id' => $domain->id,
            'summary' => $summary,
            'details' => $details,
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        ]);
    }
}
