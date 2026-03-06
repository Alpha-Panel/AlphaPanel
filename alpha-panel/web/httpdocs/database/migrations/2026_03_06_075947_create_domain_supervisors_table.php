<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // queue, reverb, pulse, horizon
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('num_procs')->default(1);
            $table->timestamps();

            $table->unique(['domain_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_supervisors');
    }
};
