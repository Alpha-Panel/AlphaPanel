<?php

namespace App\Models;

use App\Enums\DnsProvider;
use App\Enums\DomainMode;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\IpAccessMode;
use App\Enums\SslMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;

class Domain extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'fqdn',
        'parent_domain_id',
        'owner_user_id',
        'type',
        'status',
        'root_path',
        'enable_www_redirect',
        'additional_hostnames',
        'enable_worker',
        'worker_num',
        'worker_watch',
        'worker_max_requests',
        'forwarded_port',
        'php_version_id',
        'dns_provider',
        'ssl_method',
        'bypass_reverse_proxy',
        'custom_caddy_directives',
        'modsecurity_enabled',
        'modsecurity_mode',
        'modsecurity_ip_allowlist',
        'modsecurity_ip_blocklist',
        'modsecurity_disabled_rule_ids',
        'modsecurity_custom_rules',
        'cors_enabled',
        'cors_allowed_origins',
        'ip_access_mode',
        'active_ssl_certificate_id',
        'mode',
        'linked_domain_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'status' => DomainStatus::class,
            'enable_www_redirect' => 'boolean',
            'additional_hostnames' => 'array',
            'enable_worker' => 'boolean',
            'worker_watch' => 'boolean',
            'worker_max_requests' => 'integer',
            'forwarded_port' => 'integer',
            'dns_provider' => DnsProvider::class,
            'ssl_method' => SslMethod::class,
            'ip_access_mode' => IpAccessMode::class,
            'bypass_reverse_proxy' => 'boolean',
            'modsecurity_enabled' => 'boolean',
            'modsecurity_ip_allowlist' => 'array',
            'modsecurity_ip_blocklist' => 'array',
            'modsecurity_disabled_rule_ids' => 'array',
            'cors_enabled' => 'boolean',
            'mode' => DomainMode::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function parentDomain(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_domain_id');
    }

    public function linkedDomain(): BelongsTo
    {
        return $this->belongsTo(self::class, 'linked_domain_id');
    }

    public function subdomains(): HasMany
    {
        return $this->hasMany(self::class, 'parent_domain_id');
    }

    public function phpVersion(): BelongsTo
    {
        return $this->belongsTo(PhpVersion::class, 'php_version_id');
    }

    public function applyRuns(): HasMany
    {
        return $this->hasMany(ApplyRun::class, 'domain_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'domain_id');
    }

    public function managedDatabases(): HasMany
    {
        return $this->hasMany(ManagedDatabase::class, 'domain_id');
    }

    public function ftpUser(): HasOne
    {
        return $this->hasOne(FtpUser::class);
    }

    /**
     * Resolve the effective FTP username for this domain.
     * Subdomains inherit the parent domain's FTP user.
     */
    public function getEffectiveFtpUsername(): ?string
    {
        return $this->ftpUser?->username
            ?? $this->parentDomain?->ftpUser?->username;
    }

    public function phpSetting(): HasOne
    {
        return $this->hasOne(PhpSetting::class);
    }

    public function supervisors(): HasMany
    {
        return $this->hasMany(DomainSupervisor::class);
    }

    public function cronJobs(): HasMany
    {
        return $this->hasMany(DomainCronJob::class);
    }

    public function dockerServiceBindings(): HasMany
    {
        return $this->hasMany(DockerServiceDomainBinding::class);
    }

    public function ipRules(): HasMany
    {
        return $this->hasMany(DomainIpRule::class);
    }

    public function activeSslCertificate(): BelongsTo
    {
        return $this->belongsTo(SslCertificate::class, 'active_ssl_certificate_id');
    }

    public function sslCertificates(): HasMany
    {
        return $this->hasMany(SslCertificate::class);
    }

    public function dnsZone(): HasOne
    {
        // `dns_zones` lives in the `powerdns` connection as PowerDNS's native
        // `domains` table, which has no `domain_id` column — zones are linked
        // to the Laravel Domain by matching the DNS zone name to the FQDN.
        return $this->hasOne(DnsZone::class, 'name', 'fqdn');
    }

    public function usesLocalDns(): bool
    {
        return $this->dns_provider === DnsProvider::Local;
    }

    public function usesCloudflare(): bool
    {
        return $this->dns_provider === DnsProvider::Cloudflare;
    }

    /**
     * Users granted access to this domain (via pivot table).
     */
    public function authorizedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function searchableAs(): string
    {
        return 'domains';
    }

    /**
     * Only parent domains should be indexed (subdomains are embedded in parent).
     */
    public function shouldBeSearchable(): bool
    {
        return $this->parent_domain_id === null;
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'fqdn' => $this->fqdn,
            'subdomain_fqdns' => $this->subdomains()->pluck('fqdn')->implode(' '),
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'owner_user_id' => $this->owner_user_id,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    public function isSubdomain(): bool
    {
        return $this->parent_domain_id !== null;
    }

    public function isAddon(): bool
    {
        return $this->mode === DomainMode::Addon;
    }

    public function isWildcardSubdomain(): bool
    {
        return $this->mode === DomainMode::WildcardSubdomain;
    }

    public function isCatchall(): bool
    {
        return $this->mode === DomainMode::WildcardCatchall;
    }

    public function isWildcard(): bool
    {
        return $this->isWildcardSubdomain() || $this->isCatchall();
    }

    /**
     * True for modes that inherit apex cert/path (subdomain + wildcard_subdomain).
     * Used in DomainConfigService instead of isSubdomain() for cert resolution.
     */
    public function hasParentLikeBehavior(): bool
    {
        return $this->mode === DomainMode::Subdomain || $this->mode === DomainMode::WildcardSubdomain;
    }

    public function sharesWebRootWithParent(): bool
    {
        if (! $this->isSubdomain()) {
            return false;
        }

        $this->loadMissing('parentDomain');
        if (! $this->parentDomain instanceof self) {
            return false;
        }

        $domainWebRoot = $this->normalizePath($this->getWebRootPath());
        $parentWebRoot = $this->normalizePath($this->parentDomain->getWebRootPath());

        return $domainWebRoot !== '' && $domainWebRoot === $parentWebRoot;
    }

    /**
     * Derive the apex domain from fqdn.
     */
    public function getApexDomain(): string
    {
        if ($this->parent_domain_id !== null) {
            if ($this->relationLoaded('parentDomain') && $this->parentDomain) {
                return $this->parentDomain->fqdn;
            }

            $parentFqdn = self::query()
                ->whereKey($this->parent_domain_id)
                ->value('fqdn');

            if (is_string($parentFqdn) && $parentFqdn !== '') {
                return $parentFqdn;
            }
        }

        return $this->fqdn;
    }

    /**
     * Derive the subdomain slug (part before the apex).
     * E.g. "api.example.com" with apex "example.com" => "api"
     */
    public function getSubdomainSlug(): ?string
    {
        if (! $this->isSubdomain()) {
            return null;
        }

        $apex = $this->getApexDomain();
        $slug = str_replace('.'.$apex, '', $this->fqdn);

        return $slug === '*' ? 'wildcard' : $slug;
    }

    /**
     * Compute the base path for this domain/subdomain.
     */
    public function getBasePath(): string
    {
        if ($this->isCatchall()) {
            return '/var/www/vhosts/wildcard';
        }

        $apex = $this->getApexDomain();
        $base = "/var/www/vhosts/{$apex}";

        if ($this->isSubdomain()) {
            $slug = $this->getSubdomainSlug();

            return "{$base}/subdomains/{$slug}";
        }

        return $base;
    }

    /**
     * Compute the web root path.
     */
    public function getWebRootPath(): string
    {
        if ($this->linked_domain_id && $this->linkedDomain) {
            return $this->linkedDomain->getWebRootPath();
        }

        if ($this->root_path) {
            return $this->root_path;
        }

        $base = $this->getBasePath();

        if ($this->type === DomainType::CaddyWebServer) {
            return "{$base}/httpdocs/public";
        }

        return "{$base}/httpdocs";
    }

    private function normalizePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        $normalized = preg_replace('#/+#', '/', $trimmed);
        if (! is_string($normalized)) {
            return rtrim($trimmed, '/');
        }

        return rtrim($normalized, '/');
    }
}
