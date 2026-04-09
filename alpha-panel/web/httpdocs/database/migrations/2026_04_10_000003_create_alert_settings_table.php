<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('cpu_warning')->default(80);
            $table->unsignedTinyInteger('cpu_critical')->default(95);
            $table->unsignedTinyInteger('ram_warning')->default(80);
            $table->unsignedTinyInteger('ram_critical')->default(95);
            $table->unsignedTinyInteger('disk_warning')->default(80);
            $table->unsignedTinyInteger('disk_critical')->default(95);
            $table->unsignedSmallInteger('check_interval')->default(5);
            $table->unsignedSmallInteger('cooldown_minutes')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_settings');
    }
};
