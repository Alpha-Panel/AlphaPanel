<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dns_zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 10);
            $table->text('content');
            $table->unsignedInteger('ttl')->default(3600);
            $table->unsignedSmallInteger('priority')->nullable();
            $table->boolean('is_managed')->default(false);
            $table->timestamps();

            $table->index(['dns_zone_id', 'type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
