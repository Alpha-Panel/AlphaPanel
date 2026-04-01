<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained('domains')->cascadeOnDelete();
            $table->string('zone_name')->unique();
            $table->unsignedBigInteger('serial');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zones');
    }
};
