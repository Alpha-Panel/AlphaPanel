<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_pg_databases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('db_name')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('managed_pg_database_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('managed_pg_database_id')->constrained('managed_pg_databases')->cascadeOnDelete();
            $table->string('pg_user')->unique();
            $table->text('pg_password_encrypted')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_pg_database_users');
        Schema::dropIfExists('managed_pg_databases');
    }
};
