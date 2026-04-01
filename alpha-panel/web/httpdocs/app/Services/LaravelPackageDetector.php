<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SupervisorType;
use App\Models\Domain;
use Illuminate\Support\Facades\Log;

class LaravelPackageDetector
{
    /** @var array<int, list<array{name: string}>|null> */
    private array $composerCache = [];

    /**
     * Check if a specific composer package is installed for a domain.
     */
    public function isInstalled(Domain $domain, string $package): bool
    {
        $packages = $this->getComposerPackages($domain);

        if ($packages === null) {
            return false;
        }

        foreach ($packages as $entry) {
            if (($entry['name'] ?? '') === $package) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check multiple packages at once. Returns ['package/name' => bool, ...]
     *
     * @param  list<string>  $packages
     * @return array<string, bool>
     */
    public function getInstalledPackages(Domain $domain, array $packages): array
    {
        $composerPackages = $this->getComposerPackages($domain);
        $installedNames = [];

        if ($composerPackages !== null) {
            foreach ($composerPackages as $entry) {
                $installedNames[$entry['name'] ?? ''] = true;
            }
        }

        $result = [];
        foreach ($packages as $package) {
            $result[$package] = isset($installedNames[$package]);
        }

        return $result;
    }

    /**
     * Check if laravel/octane is installed AND octane:install has been run.
     */
    public function isOctaneConfigured(Domain $domain): bool
    {
        if (! $this->isInstalled($domain, 'laravel/octane')) {
            return false;
        }

        $configPath = $this->getHttpdocsPath($domain).'/config/octane.php';

        return file_exists($configPath);
    }

    /**
     * Check if the domain has a Laravel application installed.
     */
    public function isLaravel(Domain $domain): bool
    {
        return $this->isInstalled($domain, 'laravel/framework');
    }

    /**
     * Check requirements for each supervisor type.
     * Returns array of type => ['available' => bool, 'package' => string|null]
     *
     * @return array<string, array{available: bool, package: string|null}>
     */
    public function checkSupervisorRequirements(Domain $domain): array
    {
        $requirements = [
            SupervisorType::Queue->value => null,
            SupervisorType::Reverb->value => 'laravel/reverb',
            SupervisorType::Pulse->value => 'laravel/pulse',
            SupervisorType::Horizon->value => 'laravel/horizon',
        ];

        $packages = array_values(array_filter($requirements));
        $installed = $this->getInstalledPackages($domain, $packages);

        $result = [];
        foreach ($requirements as $type => $package) {
            $result[$type] = [
                'available' => $package === null || ($installed[$package] ?? false),
                'package' => $package,
            ];
        }

        return $result;
    }

    /**
     * Get the httpdocs path for a domain.
     */
    private function getHttpdocsPath(Domain $domain): string
    {
        return $domain->getBasePath().'/httpdocs';
    }

    /**
     * Read and cache the parsed composer.lock for a domain.
     * Uses a simple in-memory array cache keyed by domain ID.
     *
     * @return list<array{name: string}>|null
     */
    private function getComposerPackages(Domain $domain): ?array
    {
        $domainId = $domain->id;

        if (array_key_exists($domainId, $this->composerCache)) {
            return $this->composerCache[$domainId];
        }

        $lockPath = $this->getHttpdocsPath($domain).'/composer.lock';

        if (! file_exists($lockPath)) {
            Log::warning('LaravelPackageDetector: composer.lock not found', [
                'domain' => $domain->fqdn,
                'path' => $lockPath,
            ]);

            $this->composerCache[$domainId] = null;

            return null;
        }

        $contents = file_get_contents($lockPath);

        if ($contents === false) {
            Log::warning('LaravelPackageDetector: Failed to read composer.lock', [
                'domain' => $domain->fqdn,
                'path' => $lockPath,
            ]);

            $this->composerCache[$domainId] = null;

            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! isset($decoded['packages']) || ! is_array($decoded['packages'])) {
            Log::warning('LaravelPackageDetector: Invalid composer.lock format', [
                'domain' => $domain->fqdn,
                'path' => $lockPath,
            ]);

            $this->composerCache[$domainId] = null;

            return null;
        }

        /** @var list<array{name: string}> $packages */
        $packages = $decoded['packages'];

        $this->composerCache[$domainId] = $packages;

        return $packages;
    }
}
