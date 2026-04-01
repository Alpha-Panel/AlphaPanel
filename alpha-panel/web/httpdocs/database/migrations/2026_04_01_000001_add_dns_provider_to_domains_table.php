<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->string('dns_provider', 20)
                ->default('local')
                ->after('php_version_id');
        });

        DB::table('domains')
            ->where('cloudflare_enabled', true)
            ->update(['dns_provider' => 'cloudflare']);

        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn('cloudflare_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->boolean('cloudflare_enabled')
                ->nullable()
                ->after('php_version_id');
        });

        DB::table('domains')
            ->where('dns_provider', 'cloudflare')
            ->update(['cloudflare_enabled' => true]);

        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn('dns_provider');
        });
    }
};
