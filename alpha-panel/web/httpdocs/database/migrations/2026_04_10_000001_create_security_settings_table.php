<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_settings', function (Blueprint $table) {
            $table->id();
            $table->string('ip_filter_mode', 20)->default('off');
            $table->string('captcha_provider', 20)->default('none');
            $table->text('turnstile_site_key')->nullable();
            $table->text('turnstile_secret_key')->nullable();
            $table->text('recaptcha_site_key')->nullable();
            $table->text('recaptcha_secret_key')->nullable();
            $table->boolean('honeypot_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_settings');
    }
};
