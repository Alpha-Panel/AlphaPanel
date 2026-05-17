<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_supervisors', function (Blueprint $table): void {
            $table->unsignedSmallInteger('ssr_port')->nullable()->after('reverb_app_secret');

            $table->unique('ssr_port');
        });
    }

    public function down(): void
    {
        Schema::table('domain_supervisors', function (Blueprint $table): void {
            $table->dropUnique(['ssr_port']);
            $table->dropColumn('ssr_port');
        });
    }
};
