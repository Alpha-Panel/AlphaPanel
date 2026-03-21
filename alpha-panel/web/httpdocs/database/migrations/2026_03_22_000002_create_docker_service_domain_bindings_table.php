<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docker_service_domain_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->foreignId('docker_service_id')->constrained('docker_services')->cascadeOnDelete();
            $table->unsignedInteger('container_port');
            $table->string('path_prefix')->nullable();
            $table->timestamps();

            $table->unique(['domain_id', 'docker_service_id', 'path_prefix'], 'domain_service_path_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docker_service_domain_bindings');
    }
};
