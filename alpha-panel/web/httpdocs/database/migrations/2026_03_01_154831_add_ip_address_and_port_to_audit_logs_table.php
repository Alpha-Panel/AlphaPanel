<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('ip_address', 45)->nullable()->after('summary');
            $table->unsignedInteger('port')->nullable()->after('ip_address');

            $table->index('created_at');
            $table->index('action');
            $table->index('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['action']);
            $table->dropIndex(['ip_address']);

            $table->dropColumn(['ip_address', 'port']);
        });
    }
};
