<?php

namespace App\Services\Domain;

use App\Enums\DomainType;
use App\Models\Domain;

class ApacheConfigRenderer
{
    private string $apacheSitesBasePath;

    public function __construct(private DomainDirectoryService $directoryService)
    {
        $this->apacheSitesBasePath = config('panel.apache_sites_base');
    }

    /**
     * Generate Apache vhost for a legacy domain.
     */
    public function writeApacheConfig(Domain $domain): void
    {
        if ($domain->type !== DomainType::ApacheReverseProxy) {
            return;
        }

        $fqdn = $domain->fqdn;
        $rootPath = $domain->getWebRootPath();
        $logPath = "{$domain->getBasePath()}/logs";

        $lines = [];
        $lines[] = '<VirtualHost *:80>';
        $lines[] = "        ServerName {$fqdn}";

        if ($domain->enable_www_redirect && ! str_starts_with($fqdn, 'www.')) {
            $lines[] = "        ServerAlias www.{$fqdn}";
        }

        $additionalHostnames = $domain->additional_hostnames ?? [];
        foreach ($additionalHostnames as $hostname) {
            $lines[] = "        ServerAlias {$hostname}";
        }

        $lines[] = '';
        $lines[] = '        ServerAdmin webmaster@localhost';
        $lines[] = "        DocumentRoot {$rootPath}";
        $lines[] = '';
        $lines[] = "        <Directory {$rootPath}>";
        $lines[] = '                Options -Indexes -MultiViews +ExecCGI';
        $lines[] = '                AllowOverride All';
        $lines[] = '                Require all granted';
        $lines[] = '        </Directory>';
        $lines[] = '';
        $lines[] = '        <FilesMatch \.php$>';
        $lines[] = "            SetHandler \"proxy:unix:/run/php/{$fqdn}.sock|fcgi://localhost\"";
        $lines[] = '        </FilesMatch>';
        $lines[] = '';
        $lines[] = "        ErrorLog {$logPath}/error.log";
        $lines[] = "        CustomLog {$logPath}/access.log combined";
        $lines[] = '</VirtualHost>';

        $content = implode("\n", $lines)."\n";
        $this->directoryService->writeConfigFile("{$this->apacheSitesBasePath}/{$fqdn}.conf", $content);
    }

    /**
     * Get the Apache vhost config file path for a given FQDN.
     */
    public function apacheConfigPath(string $fqdn): string
    {
        return "{$this->apacheSitesBasePath}/{$fqdn}.conf";
    }
}
