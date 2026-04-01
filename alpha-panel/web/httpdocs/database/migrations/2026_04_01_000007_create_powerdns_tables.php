<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PowerDNS native MySQL schema (gmysql backend).
 *
 * These tables are read directly by PowerDNS Authoritative Server.
 * The application writes to them in parallel with the dns_zones/dns_records
 * tables via dual-write transactions in LocalDnsService.
 *
 * @see https://doc.powerdns.com/authoritative/backends/generic-mysql.html
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdns_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('master')->nullable();
            $table->unsignedBigInteger('last_check')->nullable();
            $table->string('type', 20)->default('NATIVE');
            $table->unsignedInteger('notified_serial')->nullable();
            $table->string('account')->nullable();
        });

        Schema::create('pdns_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('domain_id')->nullable();
            $table->string('name')->nullable();
            $table->string('type', 10)->nullable();
            $table->text('content')->nullable();
            $table->unsignedInteger('ttl')->nullable();
            $table->unsignedSmallInteger('prio')->nullable();
            $table->boolean('disabled')->default(false);
            $table->string('ordername')->nullable();
            $table->boolean('auth')->default(true);

            $table->index('domain_id', 'pdns_records_domain_id_index');
            $table->index('name', 'pdns_records_name_index');
            $table->index(['name', 'type'], 'pdns_records_name_type_index');
            $table->index('ordername', 'pdns_records_ordername_index');

            $table->foreign('domain_id')
                ->references('id')
                ->on('pdns_domains')
                ->cascadeOnDelete();
        });

        Schema::create('pdns_supermasters', function (Blueprint $table): void {
            $table->string('ip', 64);
            $table->string('nameserver');
            $table->string('account');

            $table->primary(['ip', 'nameserver']);
        });

        Schema::create('pdns_domainmetadata', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('domain_id');
            $table->string('kind')->nullable();
            $table->text('content')->nullable();

            $table->index('domain_id', 'pdns_domainmetadata_domain_id_index');

            $table->foreign('domain_id')
                ->references('id')
                ->on('pdns_domains')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdns_domainmetadata');
        Schema::dropIfExists('pdns_supermasters');
        Schema::dropIfExists('pdns_records');
        Schema::dropIfExists('pdns_domains');
    }
};
