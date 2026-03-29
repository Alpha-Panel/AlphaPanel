<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssl_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('label', 255)->nullable();
            $table->string('common_name', 255);
            $table->string('issuer', 255)->nullable();
            $table->json('san_domains')->nullable();
            $table->string('cert_path', 500)->nullable();
            $table->string('key_path', 500);
            $table->string('ca_bundle_path', 500)->nullable();
            $table->string('csr_path', 500)->nullable();
            $table->string('validation_method', 30)->nullable();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('not_after')->nullable();
            $table->string('fingerprint_sha256', 95)->nullable();
            $table->boolean('is_wildcard')->default(false);
            $table->boolean('auto_renew')->default(true);
            $table->timestamps();

            $table->index('domain_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
