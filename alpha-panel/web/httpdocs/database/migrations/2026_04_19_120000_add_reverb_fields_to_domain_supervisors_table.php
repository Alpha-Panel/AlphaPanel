<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_supervisors', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reverb_port')->nullable()->after('num_procs');
            $table->string('reverb_app_id', 64)->nullable()->after('reverb_port');
            $table->string('reverb_app_key', 64)->nullable()->after('reverb_app_id');
            $table->string('reverb_app_secret', 64)->nullable()->after('reverb_app_key');

            $table->unique('reverb_port');
        });
    }

    public function down(): void
    {
        Schema::table('domain_supervisors', function (Blueprint $table): void {
            $table->dropUnique(['reverb_port']);
            $table->dropColumn(['reverb_port', 'reverb_app_id', 'reverb_app_key', 'reverb_app_secret']);
        });
    }
};
