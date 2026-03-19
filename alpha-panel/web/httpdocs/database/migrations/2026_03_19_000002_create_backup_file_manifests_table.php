<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_file_manifests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_run_id')->constrained('backup_runs')->cascadeOnDelete();
            $table->string('domain')->index();
            $table->string('relative_path', 1024);
            $table->unsignedBigInteger('file_size');
            $table->unsignedBigInteger('file_mtime');
            $table->string('drive_file_id')->nullable();
            $table->string('action'); // 'upload' or 'delete'
            $table->timestamp('created_at')->nullable();

            $table->index(['backup_run_id', 'domain']);
        });

        // Use prefix length for the composite index to stay within MySQL key length limits
        DB::statement('ALTER TABLE `backup_file_manifests` ADD INDEX `bfm_domain_relative_path_index` (`domain`, `relative_path`(255))');
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_file_manifests');
    }
};
