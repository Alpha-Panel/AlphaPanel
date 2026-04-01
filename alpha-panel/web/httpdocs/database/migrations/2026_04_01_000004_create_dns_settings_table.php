<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('ns1')->default('ns1.example.com');
            $table->string('ns2')->default('ns2.example.com');
            $table->string('ns3')->nullable();
            $table->string('ns4')->nullable();
            $table->string('default_ip')->nullable();
            $table->string('soa_admin_email')->default('admin.example.com');
            $table->unsignedInteger('soa_refresh')->default(10800);
            $table->unsignedInteger('soa_retry')->default(3600);
            $table->unsignedInteger('soa_expire')->default(604800);
            $table->unsignedInteger('soa_minimum_ttl')->default(3600);
            $table->unsignedInteger('default_ttl')->default(3600);
            $table->foreignId('default_template_id')
                ->nullable()
                ->constrained('dns_templates')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_settings');
    }
};
