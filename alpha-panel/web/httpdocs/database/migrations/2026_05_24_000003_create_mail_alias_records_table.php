<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache table for mail provider mailboxes & aliases. Keeps panel listings
     * fast and lets us audit changes without round-tripping the provider on
     * every UI render.
     */
    public function up(): void
    {
        Schema::create('mail_provider_cache', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('kind', 16);
            $table->string('address');
            $table->string('display_name')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
            $table->unique(['domain_id', 'kind', 'address']);
            $table->index(['provider', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_provider_cache');
    }
};
