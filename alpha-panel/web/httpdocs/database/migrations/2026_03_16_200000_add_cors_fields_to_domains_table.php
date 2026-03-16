<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->boolean('cors_enabled')->default(false)->after('modsecurity_custom_rules');
            $table->string('cors_allowed_origins', 2000)->nullable()->after('cors_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn(['cors_enabled', 'cors_allowed_origins']);
        });
    }
};
