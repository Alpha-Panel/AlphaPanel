<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained('mail_domains')->cascadeOnDelete();
            $table->string('local_part');
            $table->string('full_address')->unique();
            $table->string('display_name')->nullable();
            $table->unsignedInteger('quota_mb')->default(256);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('quota_used_mb')->default(0);
            $table->timestamps();

            $table->index('mail_domain_id');
            $table->index('full_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_mailboxes');
    }
};
