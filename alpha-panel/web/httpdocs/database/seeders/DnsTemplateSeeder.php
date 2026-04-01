<?php

namespace Database\Seeders;

use App\Models\DnsTemplate;
use Illuminate\Database\Seeder;

class DnsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $template = DnsTemplate::firstOrCreate(
            ['name' => 'Standard'],
            ['is_default' => true],
        );

        if ($template->records()->exists()) {
            return;
        }

        $records = [
            ['type' => 'SOA', 'name' => '{domain}', 'content' => '{ns1} {soa_admin} {serial} {refresh} {retry} {expire} {minimum}', 'ttl' => 3600],
            ['type' => 'NS', 'name' => '{domain}', 'content' => '{ns1}', 'ttl' => 3600],
            ['type' => 'NS', 'name' => '{domain}', 'content' => '{ns2}', 'ttl' => 3600],
            ['type' => 'A', 'name' => '{domain}', 'content' => '{ip}', 'ttl' => 3600],
            ['type' => 'A', 'name' => 'www.{domain}', 'content' => '{ip}', 'ttl' => 3600],
            ['type' => 'MX', 'name' => '{domain}', 'content' => 'mail.{domain}', 'ttl' => 3600, 'priority' => 10],
            ['type' => 'TXT', 'name' => '{domain}', 'content' => 'v=spf1 a mx ~all', 'ttl' => 3600],
            ['type' => 'CAA', 'name' => '{domain}', 'content' => '0 issue "letsencrypt.org"', 'ttl' => 3600],
        ];

        foreach ($records as $record) {
            $template->records()->create($record);
        }
    }
}
