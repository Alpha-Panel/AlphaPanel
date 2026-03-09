<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('ssl_method', 30)->default('cloudflare_dns')->after('cloudflare_enabled');
            $table->boolean('bypass_reverse_proxy')->default(false)->after('ssl_method');
            $table->longText('custom_caddy_directives')->nullable()->after('bypass_reverse_proxy');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['ssl_method', 'bypass_reverse_proxy', 'custom_caddy_directives']);
        });
    }
};
