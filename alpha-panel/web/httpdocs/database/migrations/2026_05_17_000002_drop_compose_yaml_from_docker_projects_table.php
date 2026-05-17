<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docker_projects', function (Blueprint $table) {
            $table->dropColumn('compose_yaml');
        });
    }

    public function down(): void
    {
        Schema::table('docker_projects', function (Blueprint $table) {
            $table->longText('compose_yaml')->nullable()->after('display_name');
        });
    }
};
