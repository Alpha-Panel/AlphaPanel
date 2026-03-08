<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->json('modsecurity_ip_allowlist')
                ->nullable()
                ->after('modsecurity_mode');
            $table->json('modsecurity_ip_blocklist')
                ->nullable()
                ->after('modsecurity_ip_allowlist');
            $table->json('modsecurity_disabled_rule_ids')
                ->nullable()
                ->after('modsecurity_ip_blocklist');
            $table->longText('modsecurity_custom_rules')
                ->nullable()
                ->after('modsecurity_disabled_rule_ids');
        });

        Schema::create('waf_global_ip_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_or_cidr', 64);
            $table->string('action', 16); // allow|deny
            $table->string('note', 255)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['enabled', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waf_global_ip_rules');

        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn([
                'modsecurity_ip_allowlist',
                'modsecurity_ip_blocklist',
                'modsecurity_disabled_rule_ids',
                'modsecurity_custom_rules',
            ]);
        });
    }
};
