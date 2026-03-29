<?php

namespace App\Console\Commands;

use App\Enums\SslCertificateType;
use App\Enums\SslMethod;
use App\Models\Domain;
use App\Services\SslCertificateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MigrateSslRecords extends Command
{
    protected $signature = 'panel:migrate-ssl-records
        {--dry-run : Show what would be migrated without making changes}
        {--force : Re-import even if domain already has an active certificate}';

    protected $description = 'Migrate existing SSL certificates from disk to database records';

    public function handle(SslCertificateService $sslCertificateService): int
    {
        $letsEncryptBase = config('panel.letsencrypt_base');
        $selfSignedBase = config('panel.letsencrypt_selfsigned_base');
        $isDryRun = $this->option('dry-run');

        $query = Domain::query()->where('ssl_method', '!=', SslMethod::None);

        if (! $this->option('force')) {
            $query->whereNull('active_ssl_certificate_id');
        }

        $domains = $query->get();

        if ($domains->isEmpty()) {
            $this->info('No domains found that need SSL certificate migration.');

            return self::SUCCESS;
        }

        $this->info("Found {$domains->count()} domain(s) with SSL enabled but no active certificate record.");
        $this->newLine();

        $processed = 0;
        $created = 0;
        $skipped = 0;

        foreach ($domains as $domain) {
            $processed++;
            $fqdn = $domain->fqdn;

            // Check certbot live path first (Let's Encrypt)
            $liveCertPath = "{$letsEncryptBase}/{$fqdn}/fullchain.pem";
            $liveKeyPath = "{$letsEncryptBase}/{$fqdn}/privkey.pem";

            // Check self-signed path as fallback
            $selfSignedCertPath = "{$selfSignedBase}/{$fqdn}/fullchain.pem";
            $selfSignedKeyPath = "{$selfSignedBase}/{$fqdn}/privkey.pem";

            $certPath = null;
            $keyPath = null;
            $type = null;

            if (file_exists($liveCertPath) && file_exists($liveKeyPath)) {
                $certPath = $liveCertPath;
                $keyPath = $liveKeyPath;
                $type = SslCertificateType::LetsEncrypt;
            } elseif (file_exists($selfSignedCertPath) && file_exists($selfSignedKeyPath)) {
                $certPath = $selfSignedCertPath;
                $keyPath = $selfSignedKeyPath;
                $type = SslCertificateType::SelfSigned;
            }

            if ($certPath === null) {
                $this->warn("  [{$fqdn}] No certificate files found on disk. Skipping.");
                $skipped++;

                continue;
            }

            $validationMethod = match ($domain->ssl_method) {
                SslMethod::CloudflareDns => 'dns-01',
                SslMethod::WebrootHttp => 'http-01',
                default => null,
            };

            if ($isDryRun) {
                $this->line("  [DRY RUN] {$fqdn}: would create {$type->value} record from {$certPath}");
                $created++;

                continue;
            }

            try {
                $cert = $sslCertificateService->createFromDiskCert(
                    $domain,
                    $type,
                    $certPath,
                    $keyPath,
                    $validationMethod,
                );

                $domain->update(['active_ssl_certificate_id' => $cert->id]);

                $this->info("  [{$fqdn}] Created {$type->value} certificate record (ID: {$cert->id}).");
                $created++;
            } catch (\Exception $e) {
                $this->error("  [{$fqdn}] Failed: {$e->getMessage()}");
                Log::error("SSL migration failed for {$fqdn}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Summary: {$processed} domain(s) processed, {$created} certificate(s) created, {$skipped} skipped.");

        if ($isDryRun) {
            $this->warn('This was a dry run. No changes were made.');
        }

        return self::SUCCESS;
    }
}
