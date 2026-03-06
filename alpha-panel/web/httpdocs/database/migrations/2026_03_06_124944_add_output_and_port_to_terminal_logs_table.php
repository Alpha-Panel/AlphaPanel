<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminal_logs', function (Blueprint $table) {
            $table->mediumText('output')->nullable()->after('command');
            $table->unsignedInteger('port')->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('terminal_logs', function (Blueprint $table) {
            $table->dropColumn(['output', 'port']);
        });
    }
};
