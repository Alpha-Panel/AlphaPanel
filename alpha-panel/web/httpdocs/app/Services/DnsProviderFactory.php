<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DnsProvider as DnsProviderContract;
use App\Enums\DnsProvider;
use App\Models\Domain;

class DnsProviderFactory
{
    public static function for(Domain $domain): DnsProviderContract
    {
        return match ($domain->dns_provider) {
            DnsProvider::Cloudflare => app(CloudflareDnsService::class),
            DnsProvider::Local => app(LocalDnsService::class),
        };
    }
}
