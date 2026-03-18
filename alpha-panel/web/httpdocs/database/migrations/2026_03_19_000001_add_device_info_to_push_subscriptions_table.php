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
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('browser_name', 100)->nullable()->after('content_encoding');
            $table->string('browser_version', 50)->nullable()->after('browser_name');
            $table->string('os_name', 100)->nullable()->after('browser_version');
            $table->string('device_type', 20)->nullable()->after('os_name');
            $table->text('user_agent')->nullable()->after('device_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'browser_name',
                'browser_version',
                'os_name',
                'device_type',
                'user_agent',
            ]);
        });
    }
};
