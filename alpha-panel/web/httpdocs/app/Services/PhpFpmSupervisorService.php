<?php

namespace App\Services;

use App\Models\PhpVersion;
use Illuminate\Support\Facades\Log;

class PhpFpmSupervisorService
{
    public function __construct(
        private PortainerService $portainer,
    ) {}

    /**
     * Enable a PHP-FPM version by uncommenting its supervisor config and reloading.
     */
    public function enable(PhpVersion $phpVersion): void
    {
        $this->uncommentConf($phpVersion);
        $this->reloadSupervisord();

        Log::info("PHP-FPM {$phpVersion->slug} enabled via supervisor");
    }

    /**
     * Disable a PHP-FPM version by commenting out its supervisor config and reloading.
     */
    public function disable(PhpVersion $phpVersion): void
    {
        $this->commentConf($phpVersion);
        $this->reloadSupervisord();

        Log::info("PHP-FPM {$phpVersion->slug} disabled via supervisor");
    }

    private function confPath(PhpVersion $phpVersion): string
    {
        $root = config('panel.compose_project_root');

        return "{$root}/php-code-server/supervisor.d/php-fpm-{$phpVersion->slug}.conf";
    }

    /**
     * Remove leading ; from each line to activate the supervisor program.
     */
    private function uncommentConf(PhpVersion $phpVersion): void
    {
        $path = $this->confPath($phpVersion);
        $content = file_get_contents($path);
        $content = preg_replace('/^;/m', '', $content);
        file_put_contents($path, $content);

        Log::info("PHP-FPM supervisor conf uncommented: php-fpm-{$phpVersion->slug}.conf");
    }

    /**
     * Add leading ; to each non-empty line to deactivate the supervisor program.
     */
    private function commentConf(PhpVersion $phpVersion): void
    {
        $path = $this->confPath($phpVersion);
        $content = file_get_contents($path);
        $content = preg_replace('/^(?!;)(.+)$/m', ';$1', $content);
        file_put_contents($path, $content);

        Log::info("PHP-FPM supervisor conf commented: php-fpm-{$phpVersion->slug}.conf");
    }

    private function reloadSupervisord(): void
    {
        $container = config('panel.php_code_server_container', 'php-code-server');

        try {
            $this->portainer->execInContainer($container, ['supervisorctl', 'reread']);
            $this->portainer->execInContainer($container, ['supervisorctl', 'update']);

            Log::info('Supervisord reloaded in php-code-server container');
        } catch (\Throwable $e) {
            Log::error("Failed to reload supervisord in php-code-server: {$e->getMessage()}");

            throw $e;
        }
    }
}
