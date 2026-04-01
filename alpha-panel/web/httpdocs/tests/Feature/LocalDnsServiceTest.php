<?php

namespace Tests\Feature;

use App\Models\DnsRecord;
use App\Models\DnsSetting;
use App\Models\DnsTemplate;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Services\LocalDnsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalDnsServiceTest extends TestCase
{
    use DatabaseTransactions;

    private LocalDnsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LocalDnsService::class);

        $settings = DnsSetting::instance();
        $settings->update([
            'ns1' => 'ns1.test.com',
            'ns2' => 'ns2.test.com',
            'default_ip' => '10.0.0.1',
            'soa_admin_email' => 'admin.test.com',
        ]);
    }

    public function test_zone_creation_creates_both_app_and_pdns_records(): void
    {
        $domain = Domain::factory()->create([
            'fqdn' => 'example.com',
            'dns_provider' => 'local',
        ]);

        $zone = $this->service->createZone($domain);

        $this->assertInstanceOf(DnsZone::class, $zone);
        $this->assertEquals('example.com', $zone->zone_name);
        $this->assertDatabaseHas('dns_zones', ['zone_name' => 'example.com']);
        $this->assertDatabaseHas('pdns_domains', ['name' => 'example.com', 'type' => 'NATIVE']);

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'type' => 'SOA',
        ]);
        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'type' => 'NS',
            'content' => 'ns1.test.com',
        ]);
    }

    public function test_zone_deletion_removes_both_tables(): void
    {
        $domain = Domain::factory()->create([
            'fqdn' => 'delete-me.com',
            'dns_provider' => 'local',
        ]);

        $this->service->createZone($domain);
        $this->assertDatabaseHas('pdns_domains', ['name' => 'delete-me.com']);

        $this->service->deleteZone($domain);

        $this->assertDatabaseMissing('dns_zones', ['zone_name' => 'delete-me.com']);
        $this->assertDatabaseMissing('pdns_domains', ['name' => 'delete-me.com']);
    }

    public function test_record_add_creates_in_both_tables(): void
    {
        $domain = Domain::factory()->create([
            'fqdn' => 'record-test.com',
            'dns_provider' => 'local',
        ]);

        $zone = $this->service->createZone($domain);

        $record = $this->service->addRecord($zone, [
            'name' => 'www.record-test.com',
            'type' => 'A',
            'content' => '10.0.0.2',
            'ttl' => 3600,
        ]);

        $this->assertInstanceOf(DnsRecord::class, $record);
        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'name' => 'www.record-test.com',
            'type' => 'A',
            'content' => '10.0.0.2',
        ]);

        $pdnsDomainId = DB::table('pdns_domains')->where('name', 'record-test.com')->value('id');
        $this->assertDatabaseHas('pdns_records', [
            'domain_id' => $pdnsDomainId,
            'name' => 'www.record-test.com',
            'type' => 'A',
            'content' => '10.0.0.2',
        ]);
    }

    public function test_record_delete_removes_from_both_tables(): void
    {
        $domain = Domain::factory()->create([
            'fqdn' => 'del-record.com',
            'dns_provider' => 'local',
        ]);

        $zone = $this->service->createZone($domain);
        $record = $this->service->addRecord($zone, [
            'name' => 'test.del-record.com',
            'type' => 'A',
            'content' => '10.0.0.3',
            'ttl' => 3600,
        ]);

        $this->service->deleteRecord($record);
        $this->assertDatabaseMissing('dns_records', ['id' => $record->id]);
    }

    public function test_serial_increments_on_record_change(): void
    {
        $domain = Domain::factory()->create([
            'fqdn' => 'serial-test.com',
            'dns_provider' => 'local',
        ]);

        $zone = $this->service->createZone($domain);
        $initialSerial = $zone->serial;

        $this->service->addRecord($zone, [
            'name' => 'serial-test.com',
            'type' => 'TXT',
            'content' => 'test',
            'ttl' => 3600,
        ]);

        $zone->refresh();
        $this->assertGreaterThan($initialSerial, $zone->serial);
    }

    public function test_template_application_resolves_placeholders(): void
    {
        $template = DnsTemplate::create(['name' => 'Test Tpl']);
        $template->records()->create([
            'type' => 'A',
            'name' => '{domain}',
            'content' => '{ip}',
            'ttl' => 3600,
        ]);
        $template->records()->create([
            'type' => 'A',
            'name' => 'www.{domain}',
            'content' => '{ip}',
            'ttl' => 3600,
        ]);

        $domain = Domain::factory()->create([
            'fqdn' => 'tpl-test.com',
            'dns_provider' => 'local',
        ]);

        $zone = $this->service->createZone($domain, $template);

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'type' => 'A',
            'name' => 'tpl-test.com',
            'content' => '10.0.0.1',
            'is_managed' => true,
        ]);

        $this->assertDatabaseHas('dns_records', [
            'dns_zone_id' => $zone->id,
            'type' => 'A',
            'name' => 'www.tpl-test.com',
            'content' => '10.0.0.1',
            'is_managed' => true,
        ]);
    }
}
