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
        Schema::table('domain_cron_jobs', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('enabled')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domain_cron_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
