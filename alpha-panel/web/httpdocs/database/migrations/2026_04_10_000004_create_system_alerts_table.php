<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('metric', 10);
            $table->string('level', 10);
            $table->decimal('value', 5, 2);
            $table->unsignedTinyInteger('threshold');
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('resolved_value', 5, 2)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
