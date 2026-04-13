<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();
            $table->string('mail_domain')->unique();
            $table->string('mailcow_domain_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('max_mailboxes')->default(0);
            $table->unsignedInteger('quota_mb')->default(0);
            $table->string('relay_host')->nullable();
            $table->timestamps();

            $table->index('domain_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_domains');
    }
};
