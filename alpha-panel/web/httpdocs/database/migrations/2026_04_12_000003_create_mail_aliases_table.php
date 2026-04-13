<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained('mail_domains')->cascadeOnDelete();
            $table->string('address');
            $table->text('goto');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('mail_domain_id');
            $table->unique(['mail_domain_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_aliases');
    }
};
