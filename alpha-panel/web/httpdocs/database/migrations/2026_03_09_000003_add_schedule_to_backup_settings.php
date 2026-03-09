<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_settings', function (Blueprint $table) {
            $table->string('backup_schedule')->default('daily')->after('backup_retention_days');
            $table->string('backup_time')->default('03:00')->after('backup_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('backup_settings', function (Blueprint $table) {
            $table->dropColumn(['backup_schedule', 'backup_time']);
        });
    }
};
