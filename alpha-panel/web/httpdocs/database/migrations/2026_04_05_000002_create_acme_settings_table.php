<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acme_settings', function (Blueprint $table) {
            $table->id();
            $table->string('email')->default('');
            $table->boolean('staging')->default(false);
            $table->string('server_url')->default('https://acme-v02.api.letsencrypt.org/directory');
            $table->string('staging_server_url')->default('https://acme-staging-v02.api.letsencrypt.org/directory');
            $table->string('key_type', 10)->default('EC');
            $table->string('key_length', 10)->default('P-384');
            $table->unsignedInteger('dns_propagation_wait')->default(60);
            $table->unsignedInteger('local_dns_wait')->default(5);
            $table->unsignedInteger('poll_timeout')->default(300);
            $table->string('webroot_path')->default('/var/www/acme-challenge');
            $table->unsignedInteger('auto_renew_days')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acme_settings');
    }
};
