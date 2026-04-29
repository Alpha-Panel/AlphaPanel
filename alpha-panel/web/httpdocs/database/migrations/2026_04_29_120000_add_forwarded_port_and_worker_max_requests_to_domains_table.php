<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->unsignedSmallInteger('forwarded_port')
                ->default(443)
                ->after('custom_caddy_directives');

            $table->unsignedInteger('worker_max_requests')
                ->default(500)
                ->after('worker_watch');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn(['forwarded_port', 'worker_max_requests']);
        });
    }
};
