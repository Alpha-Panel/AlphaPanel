<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainSupervisor;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SiteEnvService
{
    public function __construct(private readonly PortainerService $portainer) {}

    /**
     * Upsert Reverb-related keys in the hosted site's .env file.
     *
     * Writes REVERB_* values backing the server process and VITE_REVERB_*
     * values consumed by the site's frontend build. Idempotent: re-running
     * with the same supervisor record yields the same .env.
     */
    public function setReverbEnv(Domain $domain, DomainSupervisor $supervisor): void
    {
        if ($supervisor->reverb_port === null) {
            throw new RuntimeException('Cannot sync Reverb env without an allocated port.');
        }

        $envPath = "{$domain->getBasePath()}/httpdocs/.env";

        $pairs = [
            'REVERB_APP_ID' => (string) $supervisor->reverb_app_id,
            'REVERB_APP_KEY' => (string) $supervisor->reverb_app_key,
            'REVERB_APP_SECRET' => (string) $supervisor->reverb_app_secret,
            'REVERB_HOST' => '127.0.0.1',
            'REVERB_PORT' => (string) $supervisor->reverb_port,
            'REVERB_SERVER_HOST' => '127.0.0.1',
            'REVERB_SERVER_PORT' => (string) $supervisor->reverb_port,
            'REVERB_SCHEME' => 'http',
            'VITE_REVERB_APP_KEY' => '${REVERB_APP_KEY}',
            'VITE_REVERB_HOST' => $domain->fqdn,
            'VITE_REVERB_PORT' => '443',
            'VITE_REVERB_SCHEME' => 'https',
        ];

        $this->upsertEnv($domain, $envPath, $pairs);
    }

    /**
     * Apply a set of KEY=value upserts to the given .env file.
     *
     * @param  array<string, string>  $pairs
     */
    private function upsertEnv(Domain $domain, string $envPath, array $pairs): void
    {
        $domain->loadMissing('ftpUser');
        $execUser = $domain->ftpUser?->username;

        $container = 'frankenphp';
        $envArg = escapeshellarg($envPath);

        $script = "touch {$envArg}\n";

        foreach ($pairs as $key => $value) {
            if (! preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                throw new RuntimeException("Refusing to write unsafe env key: {$key}");
            }

            $line = escapeshellarg("{$key}={$value}");

            $script .= sprintf(
                "if grep -q %s %s; then sed -i %s %s; else printf '%%s\\n' %s >> %s; fi\n",
                escapeshellarg("^{$key}="),
                $envArg,
                escapeshellarg("s|^{$key}=.*|{$key}={$value}|"),
                $envArg,
                $line,
                $envArg,
            );
        }

        $result = $this->portainer->execInContainer(
            $container,
            ['sh', '-c', $script],
            30,
            $execUser,
        );

        if (! $result->isSuccessful()) {
            $error = trim($result->errorOutput) !== '' ? trim($result->errorOutput) : trim($result->output);

            Log::error("Failed to upsert env for {$domain->fqdn}: {$error}");

            throw new RuntimeException("Failed to update {$envPath}: {$error}");
        }

        Log::info("Updated .env for {$domain->fqdn} with ".count($pairs).' keys.');
    }
}
