<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->string('chain', 20)->change();
            $table->string('action', 30)->change();
            $table->string('protocol', 10)->default('all')->change();
            $table->string('comment', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->string('chain', 10)->change();
            $table->string('action', 10)->change();
            $table->string('protocol', 10)->default('all')->change();
            $table->string('comment', 100)->nullable()->change();
        });
    }
};
