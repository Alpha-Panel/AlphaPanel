<?php

namespace App\Http\Controllers;

use App\Enums\SupervisorType;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainSupervisor;
use App\Services\PortainerService;
use App\Services\SupervisorConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DomainSupervisorController extends Controller
{
    private const FRANKENPHP_WORKERS_RESTART_URL = 'http://frankenphp:2019/frankenphp/workers/restart';

    public function index(Domain $domain): Response
    {
        $this->authorize('update', $domain);

        $supervisors = $domain->supervisors()
            ->get()
            ->keyBy(fn (DomainSupervisor $s): string => $s->type->value);

        $processes = [];

        foreach (SupervisorType::cases() as $type) {
            $existing = $supervisors->get($type->value);

            $processes[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'enabled' => $existing?->enabled ?? false,
                'num_procs' => $existing?->num_procs ?? ($type === SupervisorType::Queue ? 3 : 1),
                'supports_num_procs' => $type->supportsNumProcs(),
            ];
        }

        return Inertia::render('Domains/Supervisors', [
            'domain' => [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
            ],
            'processes' => $processes,
        ]);
    }

    public function update(Request $request, Domain $domain, SupervisorConfigService $configService): JsonResponse
    {
        $this->authorize('update', $domain);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_column(SupervisorType::cases(), 'value'))],
            'enabled' => ['required', 'boolean'],
            'num_procs' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        $type = SupervisorType::from($validated['type']);

        $supervisor = DomainSupervisor::updateOrCreate(
            ['domain_id' => $domain->id, 'type' => $type],
            [
                'enabled' => $validated['enabled'],
                'num_procs' => $type->supportsNumProcs()
                    ? ($validated['num_procs'] ?? 1)
                    : 1,
            ],
        );

        try {
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
        $this->authorize('update', $domain);

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

    public function restartFrankenphpWorkers(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        try {
            Http::timeout(5)->post(self::FRANKENPHP_WORKERS_RESTART_URL);

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
        $this->authorize('update', $domain);

        try {
            $result = $portainer->execInContainer(
                'frankenphp',
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

    private function buildAppCommandScript(Domain $domain, string $command): string
    {
        $webRoot = escapeshellarg($domain->getWebRootPath());

        return <<<SH
WEB_ROOT={$webRoot}
APP_DIR="\$WEB_ROOT"
PARENT_DIR=\$(dirname "\$WEB_ROOT")

if [ -f "\$PARENT_DIR/artisan" ]; then
  APP_DIR="\$PARENT_DIR"
fi

cd "\$APP_DIR"
{$command}
SH;
    }

    private function createAuditLog(Request $request, Domain $domain, string $action, string $summary): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'domain_id' => $domain->id,
            'summary' => $summary,
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        ]);
    }
}
