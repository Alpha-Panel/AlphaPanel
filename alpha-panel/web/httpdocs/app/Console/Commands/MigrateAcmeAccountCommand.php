<?php

namespace App\Console\Commands;

use App\Models\AcmeAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class MigrateAcmeAccountCommand extends Command
{
    protected $signature = 'acme:migrate-account
        {--path= : Path to certbot accounts directory}
        {--force : Overwrite existing account}';

    protected $description = 'Migrate existing certbot ACME account to the database';

    public function handle(): int
    {
        $basePath = $this->option('path')
            ?? config('panel.compose_project_root').'/letsencrypt/accounts';

        if (! File::isDirectory($basePath)) {
            $this->error("Accounts directory not found: {$basePath}");

            return self::FAILURE;
        }

        // Find account directories
        $accountDirs = glob("{$basePath}/*/directory/*");

        if (empty($accountDirs)) {
            $this->error('No ACME accounts found.');

            return self::FAILURE;
        }

        $imported = 0;

        foreach ($accountDirs as $accountDir) {
            $privateKeyFile = "{$accountDir}/private_key.json";
            $regrFile = "{$accountDir}/regr.json";

            if (! File::exists($privateKeyFile) || ! File::exists($regrFile)) {
                $this->warn("Skipping incomplete account: {$accountDir}");

                continue;
            }

            // Determine server URL from directory structure
            // Structure: accounts/{server-host}/directory/{account-hash}/
            $parts = explode('/accounts/', $accountDir);
            $serverHost = explode('/directory/', $parts[1] ?? '')[0] ?? '';
            $serverUrl = "https://{$serverHost}/directory";

            // Check if already exists
            $existing = AcmeAccount::forServer($serverUrl);
            if ($existing && ! $this->option('force')) {
                $this->info("Account already exists for {$serverUrl}, skipping. Use --force to overwrite.");

                continue;
            }

            // Read JWK private key
            $jwk = json_decode(File::get($privateKeyFile), true);
            if (! $jwk || ! isset($jwk['n'])) {
                $this->error("Invalid JWK in {$privateKeyFile}");

                continue;
            }

            // Convert JWK RSA to PEM
            $pem = $this->jwkToPem($jwk);
            if (! $pem) {
                $this->error("Failed to convert JWK to PEM for {$accountDir}");

                continue;
            }

            // Read registration
            $regr = json_decode(File::get($regrFile), true);
            $accountUrl = $regr['uri'] ?? null;

            // Extract public key from private key
            $privateKeyResource = openssl_pkey_get_private($pem);
            if (! $privateKeyResource) {
                $this->error("Failed to load private key for {$accountDir}");

                continue;
            }
            $details = openssl_pkey_get_details($privateKeyResource);
            $publicKeyPem = $details['key'] ?? null;

            // Read meta for email
            $metaFile = "{$accountDir}/meta.json";
            $email = null;
            if (File::exists($metaFile)) {
                $meta = json_decode(File::get($metaFile), true);
                $email = $meta['creation_host'] ?? null; // certbot stores email differently
            }

            AcmeAccount::updateOrCreate(
                ['server_url' => $serverUrl],
                [
                    'account_url' => $accountUrl,
                    'email' => $email ?? config('panel.certbot_email'),
                    'private_key_pem' => $pem,
                    'public_key_pem' => $publicKeyPem,
                ],
            );

            $this->info("Imported ACME account for {$serverUrl} (URL: {$accountUrl})");
            Log::info('Migrated certbot ACME account', [
                'server_url' => $serverUrl,
                'account_url' => $accountUrl,
            ]);

            $imported++;
        }

        $this->info("Imported {$imported} ACME account(s).");

        return self::SUCCESS;
    }

    /**
     * Convert a JWK RSA key to PEM format.
     */
    private function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA') {
            return null;
        }

        $n = $this->base64UrlDecode($jwk['n']);
        $e = $this->base64UrlDecode($jwk['e']);
        $d = $this->base64UrlDecode($jwk['d']);
        $p = $this->base64UrlDecode($jwk['p']);
        $q = $this->base64UrlDecode($jwk['q']);
        $dp = $this->base64UrlDecode($jwk['dp']);
        $dq = $this->base64UrlDecode($jwk['dq']);
        $qi = $this->base64UrlDecode($jwk['qi']);

        // Build ASN.1 DER structure for RSA private key
        $components = [
            $this->asn1Integer("\x00"),     // version
            $this->asn1Integer($n),         // modulus
            $this->asn1Integer($e),         // publicExponent
            $this->asn1Integer($d),         // privateExponent
            $this->asn1Integer($p),         // prime1
            $this->asn1Integer($q),         // prime2
            $this->asn1Integer($dp),        // exponent1
            $this->asn1Integer($dq),        // exponent2
            $this->asn1Integer($qi),        // coefficient
        ];

        $sequence = $this->asn1Sequence(implode('', $components));

        return "-----BEGIN RSA PRIVATE KEY-----\n"
            .chunk_split(base64_encode($sequence), 64, "\n")
            ."-----END RSA PRIVATE KEY-----\n";
    }

    private function base64UrlDecode(string $data): string
    {
        $data = str_replace(['-', '_'], ['+', '/'], $data);
        $padding = strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($data, true) ?: '';
    }

    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = chr($temp & 0xFF).$bytes;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function asn1Integer(string $data): string
    {
        // Ensure positive integer (prepend 0x00 if high bit set)
        if (ord($data[0]) & 0x80) {
            $data = "\x00".$data;
        }

        return "\x02".$this->asn1Length(strlen($data)).$data;
    }

    private function asn1Sequence(string $data): string
    {
        return "\x30".$this->asn1Length(strlen($data)).$data;
    }
}
