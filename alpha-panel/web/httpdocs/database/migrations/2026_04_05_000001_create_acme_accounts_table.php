<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acme_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('server_url')->unique();
            $table->string('account_url')->nullable();
            $table->string('email')->nullable();
            $table->longText('private_key_pem');
            $table->longText('public_key_pem')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acme_accounts');
    }
};
