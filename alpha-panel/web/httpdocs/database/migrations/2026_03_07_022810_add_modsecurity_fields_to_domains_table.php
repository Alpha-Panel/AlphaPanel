<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('modsecurity_enabled')
                ->default(false)
                ->after('cloudflare_enabled');
            $table->string('modsecurity_mode', 32)
                ->nullable()
                ->after('modsecurity_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['modsecurity_enabled', 'modsecurity_mode']);
        });
    }
};
