<?php

namespace App\Models;

use App\Enums\SslCertificateType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SslCertificate extends Model
{
    protected $fillable = [
        'domain_id',
        'type',
        'label',
        'common_name',
        'issuer',
        'san_domains',
        'private_key_pem',
        'certificate_pem',
        'ca_bundle_pem',
        'csr_pem',
        'validation_method',
        'not_before',
        'not_after',
        'fingerprint_sha256',
        'is_wildcard',
        'auto_renew',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SslCertificateType::class,
            'san_domains' => 'array',
            'private_key_pem' => 'encrypted',
            'certificate_pem' => 'encrypted',
            'ca_bundle_pem' => 'encrypted',
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'is_wildcard' => 'boolean',
            'auto_renew' => 'boolean',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->domain !== null
            && $this->domain->active_ssl_certificate_id === $this->id;
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->not_after !== null
            && $this->not_after->isPast();
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        return $this->not_after !== null
            && ! $this->not_after->isPast()
            && $this->not_after->diffInDays(now()) <= 30;
    }

    public function getDaysUntilExpiryAttribute(): ?int
    {
        if ($this->not_after === null) {
            return null;
        }

        return (int) now()->diffInDays($this->not_after, absolute: false);
    }

    public function getHasCertificateAttribute(): bool
    {
        return $this->certificate_pem !== null
            && $this->certificate_pem !== '';
    }
}
