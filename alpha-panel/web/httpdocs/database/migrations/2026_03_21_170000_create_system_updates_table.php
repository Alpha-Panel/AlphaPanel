<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_updates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('status', 50)->default('pending');
            $table->string('from_version', 50)->nullable();
            $table->string('to_version', 50)->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->text('message')->nullable();
            $table->longText('log')->nullable();
            $table->json('pre_flight_snapshot')->nullable();
            $table->text('error_message')->nullable();
            $table->json('rollback_info')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_updates');
    }
};
