<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DomainType;
use App\Enums\SupervisorType;
use App\Http\Requests\RunArtisanCommandRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainSupervisor;
use App\Services\LaravelPackageDetector;
use App\Services\PortainerService;
use App\Services\SupervisorConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SupervisorController extends ApiController
{
    private const MAX_OUTPUT_LENGTH = 50000;

    public function index(Domain $domain, LaravelPackageDetector $packageDetector): JsonResponse
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
            $available = $isLaravel && $req['available'];
            $missingPackage = ! $isLaravel ? 'laravel/framework' : ($req['available'] ? null : $req['package']);

            $row = [
                'type' => $type->value,
                'label' => $type->label(),
                'enabled' => $existing?->enabled ?? false,
                'num_procs' => $existing?->num_procs ?? ($type === SupervisorType::Queue ? 3 : 1),
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

            $processes[] = $row;
        }

        return response()->json(['data' => [
            'is_laravel' => $isLaravel,
            'processes' => $processes,
        ]]);
    }

    public function updateProcess(Request $request, Domain $domain, SupervisorConfigService $configService, LaravelPackageDetector $packageDetector): JsonResponse
    {
        $this->authorize('manageSupervisor', $domain);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_column(SupervisorType::cases(), 'value'))],
            'enabled' => ['required', 'boolean'],
            'num_procs' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        $type = SupervisorType::from($validated['type']);

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

            return response()->json([
                'status' => 'success',
                'enabled' => $supervisor->enabled,
                'num_procs' => $supervisor->num_procs,
                'reverb' => $type === SupervisorType::Reverb ? [
                    'port' => $supervisor->reverb_port,
                    'app_id' => $supervisor->reverb_app_id,
                    'app_key' => $supervisor->reverb_app_key,
                    'app_secret' => $supervisor->reverb_app_secret,
                ] : null,
            ]);
        } catch (\Throwable $e) {
            Log::error("Supervisor update failed for {$domain->fqdn}/{$type->value}: {$e->getMessage()}");

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

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'supervisor_restarted',
                'domain_id' => $domain->id,
                'summary' => $type->label(),
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => __(':process restart signal sent.', ['process' => $type->label()]),
            ]);
        } catch (\Throwable $e) {
            Log::error("Supervisor restart failed for {$domain->fqdn}/{$type->value}: {$e->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to restart :process: :error', [
                    'process' => $type->label(),
                    'error' => $e->getMessage(),
                ]),
            ], 500);
        }
    }

    public function restartWorkers(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
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

            return response()->json([
                'status' => 'success',
                'message' => __('FrankenPHP workers restart signal sent.'),
            ]);
        } catch (\Throwable $e) {
            Log::error("FrankenPHP workers restart signal failed for {$domain->fqdn}: {$e->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to send FrankenPHP workers restart signal: :error', [
                    'error' => $e->getMessage(),
                ]),
            ], 500);
        }
    }

    public function optimize(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
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

            return response()->json([
                'status' => 'success',
                'message' => __('Laravel optimize cache cleared and rebuilt successfully.'),
            ]);
        } catch (\Throwable $e) {
            Log::error("Laravel optimize refresh failed for {$domain->fqdn}: {$e->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to run Laravel optimize commands: :error', [
                    'error' => $e->getMessage(),
                ]),
            ], 500);
        }
    }

    public function artisan(RunArtisanCommandRequest $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        $this->authorize('runArtisan', $domain);

        $domain->loadMissing('ftpUser');

        $raw = trim($request->validated()['command']);

        $normalized = str_starts_with($raw, 'artisan ') ? 'php '.$raw : $raw;
        $parts = preg_split('/\s+/', $normalized);
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

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'artisan_command_executed',
                'domain_id' => $domain->id,
                'summary' => $command,
                'details' => $output !== '' ? $output : null,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'status' => $result->isSuccessful() ? 'success' : 'error',
                'message' => $result->isSuccessful()
                    ? __('Command executed successfully.')
                    : __('Command finished with errors.'),
                'output' => $output,
                'exit_code' => $result->exitCode,
            ]);
        } catch (\Throwable $e) {
            Log::error("Artisan command failed for {$domain->fqdn}: {$e->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to execute artisan command: :error', [
                    'error' => $e->getMessage(),
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
}
