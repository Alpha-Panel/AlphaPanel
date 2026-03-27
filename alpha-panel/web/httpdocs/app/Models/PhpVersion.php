<?php

namespace App\Models;

use App\Enums\DomainType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhpVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'fpm_pool_dir',
        'fpm_service_name',
        'is_enabled',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'php_version_id');
    }

    /**
     * Domains that actually use PHP-FPM (Apache only — Caddy uses FrankenPHP).
     */
    public function apacheDomains(): HasMany
    {
        return $this->hasMany(Domain::class, 'php_version_id')
            ->where('type', DomainType::ApacheReverseProxy);
    }
}
