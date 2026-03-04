<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apply_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->integer('progress_percent')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apply_runs');
    }
};
