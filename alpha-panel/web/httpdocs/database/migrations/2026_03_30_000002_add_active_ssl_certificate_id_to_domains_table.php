<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->foreignId('active_ssl_certificate_id')
                ->nullable()
                ->after('ssl_method')
                ->constrained('ssl_certificates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_ssl_certificate_id');
        });
    }
};
