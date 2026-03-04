<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('phpmyadmin_sso_tokens', function (Blueprint $table) {
            // 64 hex token (sha256 gibi). PK yapıyoruz.
            $table->char('token', 64)->primary();

            // Hangi MySQL kullanıcı/parola ile phpMyAdmin açılacak
            $table->string('mysql_user', 128);
            $table->text('mysql_pass');

            // İstersen override edebil (default mysql container)
            $table->string('mysql_host', 255)->default('mysql');
            $table->unsignedInteger('mysql_port')->default(3306);

            // Güvenlik: token sadece aynı IP’den çalışsın (opsiyonel)
            $table->string('client_ip', 64)->nullable()->index();

            // Token ömrü çok kısa tutulacak (örn 20-30sn)
            $table->dateTime('expires_at')->index();

            $table->timestamps();

            // Yardımcı index (expires cleanup için)
            $table->index(['expires_at', 'client_ip']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phpmyadmin_sso_tokens');
    }
};
