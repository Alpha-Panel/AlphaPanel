<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\PortainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DomainPackageManagerController extends Controller
{
    private const PHP_CODE_SERVER_CONTAINER = 'php-code-server';

    private const OUTPUT_LIMIT = 120000;

    private const NO_PACKAGE_JSON_SENTINEL = '__ALPHAPANEL_NO_PACKAGE_JSON__';

    private const NO_COMPOSER_JSON_SENTINEL = '__ALPHAPANEL_NO_COMPOSER_JSON__';

    public function index(Domain $domain): Response
    {
        $this->authorize('update', $domain);

        return Inertia::render('Domains/PackageManager', [
            'domain' => [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
            ],
        ]);
    }

    public function listNpmPackages(Domain $domain, PortainerService $portainer): JsonResponse
    {
        $this->authorize('update', $domain);

        try {
            $result = $portainer->execInContainer(
                self::PHP_CODE_SERVER_CONTAINER,
                [
                    'sh',
                    '-lc',
                    $this->buildAppCommandScript(
                        $domain,
                        'if [ ! -f package.json ]; then echo "'.self::NO_PACKAGE_JSON_SENTINEL.'"; exit 0; fi; npm ls --depth=0 --json --all --silent || true',
                    ),
                ],
                600,
            );

            $rawOutput = trim($result->output);
            if ($rawOutput === self::NO_PACKAGE_JSON_SENTINEL) {
                return response()->json([
                    'status' => 'success',
                    'has_package_json' => false,
                    'packages' => [],
                    'message' => __('No package.json found for this domain.'),
                ]);
            }

            $payload = $this->decodeJsonPayload($rawOutput);
            if ($payload === null) {
                throw new \RuntimeException('Unable to parse NPM package list output.');
            }

            $dependencies = is_array($payload['dependencies'] ?? null) ? $payload['dependencies'] : [];
            $packages = collect($dependencies)
                ->map(function (mixed $meta, string $name): array {
                    $version = '-';
                    if (is_array($meta) && is_string($meta['version'] ?? null) && trim($meta['version']) !== '') {
                        $version = trim($meta['version']);
                    }

                    return [
                        'name' => $name,
                        'version' => $version,
                    ];
                })
                ->sortBy('name')
                ->values()
                ->all();

            return response()->json([
                'status' => 'success',
                'has_package_json' => true,
                'packages' => $packages,
            ]);
        } catch (\Throwable $exception) {
            Log::error("NPM package list failed for {$domain->fqdn}: {$exception->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to list NPM packages: :error', ['error' => $exception->getMessage()]),
            ], 500);
        }
    }

    public function npmInstall(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        return $this->runCommand(
            $request,
            $domain,
            $portainer,
            command: 'npm install',
            action: 'npm_install',
            successMessage: __('NPM install completed successfully.'),
            timeout: 1800,
        );
    }

    public function npmBuild(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        return $this->runCommand(
            $request,
            $domain,
            $portainer,
            command: 'npm run build',
            action: 'npm_build',
            successMessage: __('NPM build completed successfully.'),
            timeout: 1800,
        );
    }

    public function listComposerPackages(Domain $domain, PortainerService $portainer): JsonResponse
    {
        $this->authorize('update', $domain);

        try {
            $result = $portainer->execInContainer(
                self::PHP_CODE_SERVER_CONTAINER,
                [
                    'sh',
                    '-lc',
                    $this->buildAppCommandScript(
                        $domain,
                        'if [ ! -f composer.json ]; then echo "'.self::NO_COMPOSER_JSON_SENTINEL.'"; exit 0; fi; composer show --format=json --no-interaction 2>/dev/null || composer show --locked --format=json --no-interaction 2>/dev/null || true',
                    ),
                ],
                600,
            );

            $rawOutput = trim($result->output);
            if ($rawOutput === self::NO_COMPOSER_JSON_SENTINEL) {
                return response()->json([
                    'status' => 'success',
                    'has_composer_json' => false,
                    'packages' => [],
                    'message' => __('No composer.json found for this domain.'),
                ]);
            }

            $payload = $this->decodeJsonPayload($rawOutput);
            if ($payload === null) {
                throw new \RuntimeException('Unable to parse Composer package list output.');
            }

            $installed = is_array($payload['installed'] ?? null) ? $payload['installed'] : [];
            $packages = collect($installed)
                ->filter(fn (mixed $package): bool => is_array($package) && is_string($package['name'] ?? null))
                ->map(function (array $package): array {
                    $name = (string) $package['name'];
                    $version = is_string($package['version'] ?? null) && trim($package['version']) !== ''
                        ? trim((string) $package['version'])
                        : '-';

                    return [
                        'name' => $name,
                        'version' => $version,
                    ];
                })
                ->sortBy('name')
                ->values()
                ->all();

            return response()->json([
                'status' => 'success',
                'has_composer_json' => true,
                'packages' => $packages,
            ]);
        } catch (\Throwable $exception) {
            Log::error("Composer package list failed for {$domain->fqdn}: {$exception->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to list Composer packages: :error', ['error' => $exception->getMessage()]),
            ], 500);
        }
    }

    public function composerInstall(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        $noDev = $request->boolean('no_dev');
        $command = $noDev
            ? 'composer install --no-interaction --no-dev'
            : 'composer install --no-interaction';

        return $this->runCommand(
            $request,
            $domain,
            $portainer,
            command: $command,
            action: 'composer_install',
            successMessage: $noDev
                ? __('Composer install (--no-dev) completed successfully.')
                : __('Composer install completed successfully.'),
            timeout: 1800,
        );
    }

    public function composerUpdate(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        $noDev = $request->boolean('no_dev');
        $command = $noDev
            ? 'composer update --no-interaction --no-dev'
            : 'composer update --no-interaction';

        return $this->runCommand(
            $request,
            $domain,
            $portainer,
            command: $command,
            action: 'composer_update',
            successMessage: $noDev
                ? __('Composer update (--no-dev) completed successfully.')
                : __('Composer update completed successfully.'),
            timeout: 1800,
        );
    }

    private function runCommand(
        Request $request,
        Domain $domain,
        PortainerService $portainer,
        string $command,
        string $action,
        string $successMessage,
        int $timeout,
    ): JsonResponse {
        $this->authorize('update', $domain);

        try {
            $result = $portainer->execInContainer(
                self::PHP_CODE_SERVER_CONTAINER,
                ['sh', '-lc', $this->buildAppCommandScript($domain, $command)],
                $timeout,
            );

            $combinedOutput = trim($result->output."\n".$result->errorOutput);

            if (! $result->isSuccessful()) {
                $errorMessage = $combinedOutput !== '' ? $combinedOutput : 'Unknown error.';

                throw new \RuntimeException($errorMessage);
            }

            $this->createAuditLog(
                $request,
                $domain,
                $action.'_executed',
                "Command executed: {$command}",
            );

            $outputPayload = $this->trimOutput($combinedOutput);

            return response()->json([
                'status' => 'success',
                'message' => $successMessage,
                'output' => $outputPayload['output'],
                'output_truncated' => $outputPayload['truncated'],
            ]);
        } catch (\Throwable $exception) {
            Log::error("Package command failed for {$domain->fqdn} ({$command}): {$exception->getMessage()}");

            $this->createAuditLog(
                $request,
                $domain,
                $action.'_failed',
                $this->trimOutput($exception->getMessage())['output'],
            );

            $outputPayload = $this->trimOutput($exception->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to execute command: :error', ['error' => $this->singleLine($exception->getMessage())]),
                'output' => $outputPayload['output'],
                'output_truncated' => $outputPayload['truncated'],
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

if [ -f "\$PARENT_DIR/artisan" ] || [ -f "\$PARENT_DIR/composer.json" ] || [ -f "\$PARENT_DIR/package.json" ]; then
  APP_DIR="\$PARENT_DIR"
fi

cd "\$APP_DIR"
{$command}
SH;
    }

    private function decodeJsonPayload(string $rawOutput): ?array
    {
        $trimmed = trim($rawOutput);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $jsonChunk = substr($trimmed, $start, ($end - $start) + 1);
        if (! is_string($jsonChunk) || trim($jsonChunk) === '') {
            return null;
        }

        $decodedChunk = json_decode($jsonChunk, true);

        return is_array($decodedChunk) ? $decodedChunk : null;
    }

    /**
     * @return array{output: string, truncated: bool}
     */
    private function trimOutput(string $output): array
    {
        $normalized = str_replace("\0", '', trim($output));
        if ($normalized === '') {
            return [
                'output' => '',
                'truncated' => false,
            ];
        }

        if (mb_strlen($normalized) <= self::OUTPUT_LIMIT) {
            return [
                'output' => $normalized,
                'truncated' => false,
            ];
        }

        return [
            'output' => mb_substr($normalized, 0, self::OUTPUT_LIMIT)."\n\n[output truncated]",
            'truncated' => true,
        ];
    }

    private function singleLine(string $value): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if ($line === '') {
            return __('Unknown error.');
        }

        return $line;
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
