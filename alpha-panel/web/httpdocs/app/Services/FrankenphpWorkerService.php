<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Domain;
use RuntimeException;

class FrankenphpWorkerService
{
    /**
     * Signal file watched by public/frankenphp-worker.php, relative to the app root.
     */
    public const RESTART_SIGNAL = 'storage/framework/worker.restart';

    public const MODE_ROLLING = 'rolling';

    public const MODE_RELOAD = 'reload';

    public function __construct(
        private PortainerService $portainer,
    ) {}

    /**
     * Restart the worker threads of a site so they pick up newly deployed code.
     *
     * A site whose worker script watches the signal file is restarted by renewing
     * that file: its threads restart one by one after their current request, and
     * nothing outside the site is involved. Any other site falls back to
     * `frankenphp reload`, which restarts the PHP engine for every site.
     *
     * The admin API's /frankenphp/workers/restart endpoint is deliberately not used:
     * it restarts every site's workers at once and force-kills requests that are
     * still running after a few seconds.
     *
     * @return self::MODE_*
     *
     * @throws RuntimeException
     */
    public function restart(Domain $domain): string
    {
        if ($this->supportsRollingRestart($domain)) {
            $this->signalRollingRestart($domain);

            return self::MODE_ROLLING;
        }

        $result = $this->portainer->execInContainer(
            (string) config('panel.frankenphp_container', 'frankenphp'),
            ['frankenphp', 'reload', '--config', (string) config('panel.caddy_reload_config', '/etc/frankenphp/Caddyfile')],
            (int) config('panel.caddy_reload_timeout', 300),
        );

        if (! $result->isSuccessful()) {
            $error = trim($result->errorOutput) !== '' ? trim($result->errorOutput) : trim($result->output);

            throw new RuntimeException($error !== '' ? $error : 'Worker restart command failed.');
        }

        return self::MODE_RELOAD;
    }

    /**
     * Whether the site's worker script watches the restart signal file.
     */
    public function supportsRollingRestart(Domain $domain): bool
    {
        $script = $this->pathInsideSite($domain, $this->appPath($domain).'/public/frankenphp-worker.php');

        if ($script === null || ! is_file($script)) {
            return false;
        }

        $head = @file_get_contents($script, false, null, 0, 65536);

        return is_string($head) && str_contains($head, basename(self::RESTART_SIGNAL));
    }

    /**
     * Replace the signal file so its modification time becomes "now".
     *
     * The panel runs as root and the site's directories belong to the tenant, so
     * nothing here may follow a link the tenant planted: the directory has to
     * resolve inside the site, and the file is removed and created exclusively
     * instead of being touched. Ownership and mode are left alone on purpose,
     * because chown and chmod act on whatever the path points to by then.
     *
     * @throws RuntimeException
     */
    private function signalRollingRestart(Domain $domain): void
    {
        $directory = $this->pathInsideSite($domain, $this->appPath($domain).'/'.dirname(self::RESTART_SIGNAL));

        if ($directory === null || ! is_dir($directory)) {
            throw new RuntimeException('The worker restart signal directory is missing or outside the site.');
        }

        $signal = $directory.DIRECTORY_SEPARATOR.basename(self::RESTART_SIGNAL);

        @unlink($signal);

        $handle = @fopen($signal, 'x');

        if ($handle === false) {
            throw new RuntimeException("Could not write the worker restart signal file: {$signal}");
        }

        fclose($handle);
    }

    /**
     * Resolve a path and return it only when it stays inside the site's own tree.
     */
    private function pathInsideSite(Domain $domain, string $path): ?string
    {
        $root = realpath($domain->getBasePath());
        $resolved = realpath($path);

        if ($root === false || $resolved === false) {
            return null;
        }

        return str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) ? $resolved : null;
    }

    private function appPath(Domain $domain): string
    {
        return $domain->getBasePath().'/httpdocs';
    }
}
