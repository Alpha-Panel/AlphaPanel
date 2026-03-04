<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->string('fqdn')->unique();
            $table->foreignId('parent_domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['legacy', 'modern']);
            $table->enum('status', ['active', 'disabled', 'pending_cert', 'failed'])->default('pending_cert');
            $table->string('root_path')->nullable();
            $table->boolean('enable_www_redirect')->default(false);
            $table->json('additional_hostnames')->nullable();
            $table->boolean('enable_worker')->default(false);
            $table->integer('worker_num')->nullable();
            $table->boolean('worker_watch')->default(false);
            $table->foreignId('php_version_id')->nullable()->constrained('php_versions')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
