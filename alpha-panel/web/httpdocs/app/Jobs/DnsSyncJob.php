<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\CloudflareDnsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DnsSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public Domain $domain,
        public ?string $targetIp = null,
        public ?int $triggeredBy = null,
        public bool $proxied = false,
        public ?string $actorIpAddress = null,
        public ?int $actorPort = null,
    ) {}

    public function handle(CloudflareDnsService $cloudflare): void
    {
        $domain = $this->domain;
        $ip = $this->targetIp ?? config('panel.server_ip', '127.0.0.1');

        try {
            $apexDomain = $domain->getApexDomain();
            $cloudflare->addSubdomainRecord($apexDomain, $domain->fqdn, $ip, $this->proxied);
            Log::info("DNS A record created for {$domain->fqdn} -> {$ip} (proxied=".($this->proxied ? 'true' : 'false').')');

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'dns_synced',
                'domain_id' => $domain->id,
                'summary' => "DNS A record synced for {$domain->fqdn} -> {$ip} (proxied=".($this->proxied ? 'true' : 'false').').',
                'ip_address' => $this->actorIpAddress,
                'port' => $this->actorPort,
            ]);
        } catch (\Throwable $e) {
            Log::error("DNS sync failed for {$domain->fqdn}: {$e->getMessage()}");

            AuditLog::create([
                'user_id' => $this->triggeredBy,
                'action' => 'dns_sync_failed',
                'domain_id' => $domain->id,
                'summary' => "DNS sync failed for {$domain->fqdn}: {$e->getMessage()}",
                'ip_address' => $this->actorIpAddress,
                'port' => $this->actorPort,
            ]);
        }
    }
}
