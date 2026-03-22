<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_ip_rules', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
        });

        Schema::table('domain_ip_rules', function (Blueprint $table) {
            $table->dropUnique(['domain_id', 'ip_address']);
            $table->string('path', 255)->default('')->after('ip_address');
            $table->unique(['domain_id', 'ip_address', 'path']);
            $table->foreign('domain_id')->references('id')->on('domains')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domain_ip_rules', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
        });

        Schema::table('domain_ip_rules', function (Blueprint $table) {
            $table->dropUnique(['domain_id', 'ip_address', 'path']);
            $table->dropColumn('path');
            $table->unique(['domain_id', 'ip_address']);
            $table->foreign('domain_id')->references('id')->on('domains')->cascadeOnDelete();
        });
    }
};
