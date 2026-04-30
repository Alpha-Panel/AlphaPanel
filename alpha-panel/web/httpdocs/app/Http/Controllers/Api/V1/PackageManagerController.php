<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Domain;
use App\Services\PortainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PackageManagerController extends ApiController
{
    private const PHP_CODE_SERVER_CONTAINER = 'php-code-server';

    private const OUTPUT_LIMIT = 120000;

    private const NO_PACKAGE_JSON_SENTINEL = '__ALPHAPANEL_NO_PACKAGE_JSON__';

    private const NO_COMPOSER_JSON_SENTINEL = '__ALPHAPANEL_NO_COMPOSER_JSON__';

    public function index(Domain $domain): JsonResponse
    {
        return response()->json(['data' => ['fqdn' => $domain->fqdn, 'web_root' => $domain->getWebRootPath()]]);
    }

    public function npmPackages(Domain $domain, PortainerService $portainer): JsonResponse
    {
        try {
            $result = $portainer->execInContainer(
                self::PHP_CODE_SERVER_CONTAINER,
                ['sh', '-lc', $this->buildScript($domain, 'if [ ! -f package.json ]; then echo "'.self::NO_PACKAGE_JSON_SENTINEL.'"; exit 0; fi; npm ls --depth=0 --json --all --silent || true')],
                600,
            );

            $raw = trim($result->output);
            if ($raw === self::NO_PACKAGE_JSON_SENTINEL) {
                return response()->json(['data' => ['has_package_json' => false, 'packages' => []]]);
            }

            $payload = $this->decodeJson($raw) ?? [];
            $deps = is_array($payload['dependencies'] ?? null) ? $payload['dependencies'] : [];
            $packages = collect($deps)->map(fn (mixed $m, string $n) => ['name' => $n, 'version' => is_array($m) && is_string($m['version'] ?? null) ? trim($m['version']) : '-'])->sortBy('name')->values()->all();

            return response()->json(['data' => ['has_package_json' => true, 'packages' => $packages]]);
        } catch (\Throwable $e) {
            Log::error("NPM list failed for {$domain->fqdn}: {$e->getMessage()}");

            return response()->json(['message' => __('Failed to list NPM packages: :error', ['error' => $e->getMessage()])], 500);
        }
    }

    public function npmInstall(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        return $this->runCommand($domain, $portainer, 'npm install', 1800);
    }

    public function npmBuild(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        return $this->runCommand($domain, $portainer, 'npm run build', 1800);
    }

    public function npmAuditFix(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        return $this->runCommand($domain, $portainer, 'npm audit fix', 1800);
    }

    public function composerPackages(Domain $domain, PortainerService $portainer): JsonResponse
    {
        try {
            $result = $portainer->execInContainer(
                self::PHP_CODE_SERVER_CONTAINER,
                ['sh', '-lc', $this->buildScript($domain, 'if [ ! -f composer.json ]; then echo "'.self::NO_COMPOSER_JSON_SENTINEL.'"; exit 0; fi; composer show --format=json --no-interaction 2>/dev/null || true')],
                600,
            );

            $raw = trim($result->output);
            if ($raw === self::NO_COMPOSER_JSON_SENTINEL) {
                return response()->json(['data' => ['has_composer_json' => false, 'packages' => []]]);
            }

            $payload = $this->decodeJson($raw) ?? [];
            $installed = is_array($payload['installed'] ?? null) ? $payload['installed'] : [];
            $packages = collect($installed)->filter(fn (mixed $p) => is_array($p) && is_string($p['name'] ?? null))->map(fn (array $p) => ['name' => $p['name'], 'version' => is_string($p['version'] ?? null) ? trim($p['version']) : '-'])->sortBy('name')->values()->all();

            return response()->json(['data' => ['has_composer_json' => true, 'packages' => $packages]]);
        } catch (\Throwable $e) {
            Log::error("Composer list failed for {$domain->fqdn}: {$e->getMessage()}");

            return response()->json(['message' => __('Failed to list Composer packages: :error', ['error' => $e->getMessage()])], 500);
        }
    }

    public function composerInstall(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        return $this->runCommand($domain, $portainer, 'composer install --no-interaction', 1800);
    }

    public function composerUpdate(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        return $this->runCommand($domain, $portainer, 'composer update --no-interaction', 1800);
    }

    public function composerDumpAutoload(Request $request, Domain $domain, PortainerService $portainer): JsonResponse
    {
        return $this->runCommand($domain, $portainer, 'composer dump-autoload --no-interaction', 1800);
    }

    private function runCommand(Domain $domain, PortainerService $portainer, string $command, int $timeout): JsonResponse
    {
        try {
            $result = $portainer->execInContainer(
                self::PHP_CODE_SERVER_CONTAINER,
                ['sh', '-lc', $this->buildScript($domain, $command)],
                $timeout,
            );

            $combined = trim($result->output."\n".$result->errorOutput);

            if (! $result->isSuccessful()) {
                throw new \RuntimeException($combined ?: 'Unknown error.');
            }

            return response()->json(['data' => ['output' => $this->trimOutput($combined), 'command' => $command]]);
        } catch (\Throwable $e) {
            Log::error("Package command failed for {$domain->fqdn} ({$command}): {$e->getMessage()}");

            return response()->json(['message' => __('Failed to execute command: :error', ['error' => $e->getMessage()])], 500);
        }
    }

    private function buildScript(Domain $domain, string $command): string
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

    private function decodeJson(string $raw): ?array
    {
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $chunk = substr($raw, $start, ($end - $start) + 1);
        $decoded = json_decode($chunk, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function trimOutput(string $output): string
    {
        $out = str_replace("\0", '', trim($output));

        return mb_strlen($out) > self::OUTPUT_LIMIT
            ? mb_substr($out, 0, self::OUTPUT_LIMIT)."\n\n[output truncated]"
            : $out;
    }
}
