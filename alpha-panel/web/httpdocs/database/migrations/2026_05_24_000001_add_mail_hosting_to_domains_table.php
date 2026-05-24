<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->string('mail_hosting', 16)->default('disabled')->after('dns_provider');
            $table->string('mail_remote_mx_host')->nullable()->after('mail_hosting');
            $table->unsignedSmallInteger('mail_remote_mx_priority')->default(10)->after('mail_remote_mx_host');
            $table->string('mail_provider_external_id')->nullable()->after('mail_remote_mx_priority');
            $table->index('mail_hosting');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropIndex(['mail_hosting']);
            $table->dropColumn([
                'mail_hosting',
                'mail_remote_mx_host',
                'mail_remote_mx_priority',
                'mail_provider_external_id',
            ]);
        });
    }
};
