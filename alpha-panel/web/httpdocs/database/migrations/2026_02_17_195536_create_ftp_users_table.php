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
        Schema::create('ftp_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained('domains')->cascadeOnDelete();
            $table->string('username')->unique();
            $table->string('home_path');
            $table->unsignedInteger('uid')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ftp_users');
    }
};
