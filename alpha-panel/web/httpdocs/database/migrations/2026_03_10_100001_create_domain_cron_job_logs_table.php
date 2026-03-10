<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_cron_job_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_cron_job_id')->constrained('domain_cron_jobs')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 20)->default('running');
            $table->text('output')->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_cron_job_logs');
    }
};
