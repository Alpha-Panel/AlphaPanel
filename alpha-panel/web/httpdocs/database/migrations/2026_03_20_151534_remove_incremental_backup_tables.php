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
        Schema::dropIfExists('backup_file_manifests');
        Schema::dropIfExists('backup_restore_runs');

        if (Schema::hasColumn('backup_settings', 'backup_mode')) {
            Schema::table('backup_settings', function (Blueprint $table) {
                $table->dropColumn('backup_mode');
            });
        }

        if (Schema::hasColumn('backup_runs', 'backup_mode')) {
            Schema::table('backup_runs', function (Blueprint $table) {
                $table->dropColumn('backup_mode');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
