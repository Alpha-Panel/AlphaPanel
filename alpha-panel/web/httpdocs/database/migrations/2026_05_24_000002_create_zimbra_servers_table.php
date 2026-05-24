<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zimbra_servers', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('admin_url');
            $table->string('admin_user');
            $table->text('admin_password_encrypted');
            $table->string('default_mx_host');
            $table->unsignedSmallInteger('default_mx_priority')->default(10);
            $table->string('default_spf_include')->nullable();
            $table->boolean('verify_tls')->default(true);
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_health_status', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zimbra_servers');
    }
};
