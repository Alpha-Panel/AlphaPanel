<?php

namespace App\Models;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'php_version_id',
        'cloudflare_enabled',
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
            'cloudflare_enabled' => 'boolean',
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

    public function phpSetting(): HasOne
    {
        return $this->hasOne(PhpSetting::class);
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

        return str_replace('.'.$apex, '', $this->fqdn);
    }

    /**
     * Compute the base path for this domain/subdomain.
     */
    public function getBasePath(): string
    {
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
        if ($this->root_path) {
            return $this->root_path;
        }

        $base = $this->getBasePath();

        if ($this->type === DomainType::CaddyWebServer) {
            return "{$base}/httpdocs/public";
        }

        return "{$base}/httpdocs";
    }
}
