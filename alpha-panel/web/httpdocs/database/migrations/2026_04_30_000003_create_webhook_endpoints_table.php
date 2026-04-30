<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->text('secret');
            $table->json('events');
            $table->boolean('active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('last_status_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
