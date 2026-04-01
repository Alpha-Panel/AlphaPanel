<?php

namespace Database\Factories;

use App\Enums\DnsProvider;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\PhpVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $fqdn = $this->faker->unique()->domainName();

        return [
            'fqdn' => $fqdn,
            'parent_domain_id' => null,
            'owner_user_id' => User::factory(),
            'type' => DomainType::CaddyWebServer,
            'status' => DomainStatus::PendingCert,
            'root_path' => null,
            'enable_www_redirect' => $this->faker->boolean(),
            'additional_hostnames' => [],
            'enable_worker' => false,
            'worker_num' => null,
            'worker_watch' => false,
            'php_version_id' => null,
            'dns_provider' => DnsProvider::Local,
            'modsecurity_enabled' => false,
            'modsecurity_mode' => null,
            'modsecurity_ip_allowlist' => [],
            'modsecurity_ip_blocklist' => [],
            'modsecurity_disabled_rule_ids' => [],
            'modsecurity_custom_rules' => null,
            'cors_enabled' => false,
            'cors_allowed_origins' => null,
        ];
    }

    public function apacheReverseProxy(): static
    {
        return $this->state(function (array $attributes): array {
            $phpVersion = PhpVersion::inRandomOrder()->first()
                ?? PhpVersion::factory()->create(['is_enabled' => true]);

            return [
                'type' => DomainType::ApacheReverseProxy,
                'php_version_id' => $phpVersion->id,
            ];
        });
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => DomainStatus::Active,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'status' => DomainStatus::Disabled,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => DomainStatus::Failed,
        ]);
    }

    public function withWorker(int $num = 5, bool $watch = false): static
    {
        return $this->state(fn (): array => [
            'enable_worker' => true,
            'worker_num' => $num,
            'worker_watch' => $watch,
            'type' => DomainType::CaddyWebServer,
        ]);
    }

    public function subdomain(Domain $parent): static
    {
        $slug = $this->faker->word();

        return $this->state(fn (): array => [
            'fqdn' => $slug.'.'.$parent->fqdn,
            'parent_domain_id' => $parent->id,
            'owner_user_id' => $parent->owner_user_id,
        ]);
    }
}
