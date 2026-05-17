<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_supervisors', function (Blueprint $table): void {
            $table->string('queue_names')->nullable()->after('num_procs');
        });
    }

    public function down(): void
    {
        Schema::table('domain_supervisors', function (Blueprint $table): void {
            $table->dropColumn('queue_names');
        });
    }
};
