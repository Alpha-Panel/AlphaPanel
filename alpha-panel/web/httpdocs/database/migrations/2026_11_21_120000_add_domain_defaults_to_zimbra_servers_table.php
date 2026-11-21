<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zimbra_servers', function (Blueprint $table): void {
            $table->string('inbound_smtp_host')->nullable()->after('default_spf_include');
            $table->string('default_timezone', 64)->nullable()->after('inbound_smtp_host');
        });
    }

    public function down(): void
    {
        Schema::table('zimbra_servers', function (Blueprint $table): void {
            $table->dropColumn(['inbound_smtp_host', 'default_timezone']);
        });
    }
};
