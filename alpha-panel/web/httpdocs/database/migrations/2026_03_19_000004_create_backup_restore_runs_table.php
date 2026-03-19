<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_restore_runs', function (Blueprint $table) {
            $table->id();
            $table->string('restore_type'); // 'website' or 'database'
            $table->string('source_mode'); // 'full' or 'incremental'
            $table->string('status')->default('pending'); // pending, downloading, restoring, completed, failed, cancelled
            $table->string('target'); // domain name or DB name
            $table->string('source_drive_folder_id')->nullable();
            $table->string('source_drive_file_id')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('progress_percent')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restore_runs');
    }
};
