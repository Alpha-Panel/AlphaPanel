<?php

namespace App\Services;

use App\Enums\SupervisorType;
use App\Models\Domain;
use App\Models\DomainSupervisor;
use Illuminate\Support\Facades\Log;

class SupervisorConfigService
{
    private const CONF_DIR = '/etc/frankenphp-supervisor';

    private const FRANKENPHP_CONTAINER = 'frankenphp';

    public function sync(Domain $domain): void
    {
        $supervisors = $domain->supervisors()->get();

        foreach (SupervisorType::cases() as $type) {
            $supervisor = $supervisors->firstWhere('type', $type);
            $confPath = $this->confPath($domain, $type);

            if ($supervisor && $supervisor->enabled) {
                $this->writeConf($domain, $supervisor);
            } else {
                $this->removeConf($confPath);
            }
        }

        $this->reloadSupervisord();
    }

    public function syncSingle(DomainSupervisor $supervisor): void
    {
        $supervisor->loadMissing('domain');
        $confPath = $this->confPath($supervisor->domain, $supervisor->type);

        if ($supervisor->enabled) {
            $this->writeConf($supervisor->domain, $supervisor);
        } else {
            $this->removeConf($confPath);
        }

        $this->reloadSupervisord();
    }

    public function removeAll(Domain $domain): void
    {
        foreach (SupervisorType::cases() as $type) {
            $this->removeConf($this->confPath($domain, $type));
        }

        $this->reloadSupervisord();
    }

    private function writeConf(Domain $domain, DomainSupervisor $supervisor): void
    {
        $fqdn = $domain->fqdn;
        $slug = str_replace('.', '-', $fqdn);
        $type = $supervisor->type;
        $httpdocs = "/var/www/vhosts/{$fqdn}/httpdocs";

        $programName = $slug.'-'.$type->programSuffix();
        $command = "/usr/local/bin/php {$httpdocs}/artisan {$type->artisanCommand()}";
        $logFile = "{$httpdocs}/storage/logs/{$type->logFile()}";
        $numProcs = $type->supportsNumProcs() ? $supervisor->num_procs : 1;

        $conf = <<<CONF
        [program:{$programName}]
        process_name=%(program_name)s_%(process_num)02d
        command={$command}
        autostart=true
        autorestart=true
        stopasgroup=true
        killasgroup=true
        numprocs={$numProcs}
        redirect_stderr=true
        stdout_logfile={$logFile}
        stopwaitsecs=3600
        startsecs=0
        CONF;

        $confPath = $this->confPath($domain, $type);
        file_put_contents($confPath, $conf);

        Log::info("Supervisor conf written: {$confPath}");
    }

    private function removeConf(string $confPath): void
    {
        if (file_exists($confPath)) {
            unlink($confPath);
            Log::info("Supervisor conf removed: {$confPath}");
        }
    }

    private function confPath(Domain $domain, SupervisorType $type): string
    {
        return self::CONF_DIR.'/'.$domain->fqdn.'-'.$type->value.'.conf';
    }

    private function reloadSupervisord(): void
    {
        try {
            /** @var PortainerService $portainer */
            $portainer = app(PortainerService::class);
            $portainer->execInContainer(self::FRANKENPHP_CONTAINER, ['supervisorctl', 'reread']);
            $portainer->execInContainer(self::FRANKENPHP_CONTAINER, ['supervisorctl', 'update']);

            Log::info('Supervisord reloaded in frankenphp container');
        } catch (\Throwable $e) {
            Log::error("Failed to reload supervisord: {$e->getMessage()}");
        }
    }
}
