<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropForeign(['parent_domain_id']);
            $table->foreign('parent_domain_id')->references('id')->on('domains')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropForeign(['parent_domain_id']);
            $table->foreign('parent_domain_id')->references('id')->on('domains')->nullOnDelete();
        });
    }
};
