<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DnsProvider;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\SslCertificateType;
use App\Enums\SslMethod;
use App\Models\AcmeSetting;
use App\Models\Domain;
use App\Models\User;
use App\Services\Acme\AcmeService;
use App\Services\SslCertificateService;
use Illuminate\Console\Command;

class IssueInstallerCertCommand extends Command
{
    protected $signature = 'panel:issue-installer-cert
        {--base= : Base (apex) domain to issue wildcard cert for}
        {--token-file= : Path to Cloudflare INI file (dns_cloudflare_api_token=)}
        {--admin-email= : Contact email for ACME account}';

    protected $description = 'Issue a Let\'s Encrypt wildcard certificate via Cloudflare DNS-01 during initial installation';

    public function handle(AcmeService $acmeService, SslCertificateService $sslCertificateService): int
    {
        $base = (string) $this->option('base');
        $tokenFile = (string) $this->option('token-file');
        $email = (string) $this->option('admin-email');

        if ($base === '' || $tokenFile === '' || $email === '') {
            $this->error('--base, --token-file, and --admin-email are required');

            return self::FAILURE;
        }

        if (! is_file($tokenFile)) {
            $this->error("Token file not found: {$tokenFile}");

            return self::FAILURE;
        }

        if ($this->extractTokenFromIni($tokenFile) === null) {
            $this->error("Could not parse dns_cloudflare_api_token from {$tokenFile}");

            return self::FAILURE;
        }

        $setting = AcmeSetting::instance();
        $setting->email = $email;
        $setting->staging = false;
        $setting->save();

        $ownerId = User::query()->orderBy('id')->value('id');

        $domain = Domain::firstOrCreate(
            ['fqdn' => $base],
            [
                'type' => DomainType::CaddyWebServer,
                'status' => DomainStatus::PendingCert,
                'dns_provider' => DnsProvider::Cloudflare,
                'ssl_method' => SslMethod::CloudflareDns,
                'owner_user_id' => $ownerId,
            ],
        );

        $this->info("[acme] Issuing wildcard certificate for {$base} and *.{$base}");

        $result = $acmeService->requestCertificateDnsCloudflare(
            $domain,
            function (int|float $progress, string $message): void {
                $pct = (int) $progress;
                $this->line("[acme] [{$pct}%] {$message}");
            },
        );

        if (! $result->success) {
            $this->error("[acme] Failed: {$result->error}");

            return self::FAILURE;
        }

        $this->info('[acme] Certificate obtained, storing and activating...');

        $cert = $sslCertificateService->createFromPem(
            $domain,
            SslCertificateType::LetsEncrypt,
            $result->fullchainPem ?? '',
            $result->privateKeyPem ?? '',
            'cloudflare_dns',
        );

        $sslCertificateService->activate($domain, $cert);
        $sslCertificateService->syncToLivePath($domain, $cert);

        $domain->status = DomainStatus::Active;
        $domain->save();

        $this->info('[acme] Wildcard certificate activated successfully.');

        return self::SUCCESS;
    }

    private function extractTokenFromIni(string $path): ?string
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }
        foreach ($lines as $line) {
            if (preg_match('/^\s*dns_cloudflare_api_token\s*=\s*(\S+)\s*$/', $line, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
