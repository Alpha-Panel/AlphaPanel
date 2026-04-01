<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_template_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dns_template_id')->constrained('dns_templates')->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('name');
            $table->text('content');
            $table->unsignedInteger('ttl')->default(3600);
            $table->unsignedSmallInteger('priority')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_template_records');
    }
};
