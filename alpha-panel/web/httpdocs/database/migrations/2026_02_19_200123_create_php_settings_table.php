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
        Schema::create('php_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_errors', 10)->default('Off');
            $table->string('error_reporting', 100)->default('E_ALL');
            $table->string('memory_limit', 20)->default('256M');
            $table->string('post_max_size', 20)->default('256M');
            $table->string('upload_max_filesize', 20)->default('256M');
            $table->unsignedInteger('max_execution_time')->default(3000);
            $table->unsignedInteger('max_input_time')->default(3000);
            $table->unsignedInteger('max_input_vars')->default(3000);
            $table->unsignedInteger('session_gc_maxlifetime')->default(1440);
            $table->unsignedInteger('session_cookie_lifetime')->default(1440);
            $table->string('opcache_enable', 10)->default('On');
            $table->string('date_timezone', 100)->default('Europe/Istanbul');
            $table->string('allow_url_fopen', 10)->default('On');
            $table->text('disable_functions')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('php_settings');
    }
};
