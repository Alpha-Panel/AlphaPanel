<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docker_services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name')->nullable();
            $table->string('image');
            $table->string('tag')->default('latest');
            $table->string('status', 50)->default('pending');
            $table->string('restart_policy', 50)->default('unless-stopped');
            $table->string('container_id')->nullable();
            $table->json('environment_variables')->nullable();
            $table->json('volumes')->nullable();
            $table->json('ports')->nullable();
            $table->json('resource_limits')->nullable();
            $table->json('networks')->nullable();
            $table->string('hostname')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('image');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docker_services');
    }
};
