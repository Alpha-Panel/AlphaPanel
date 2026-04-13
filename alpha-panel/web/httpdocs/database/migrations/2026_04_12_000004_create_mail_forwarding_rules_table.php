<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_forwarding_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_mailbox_id')->constrained('mail_mailboxes')->cascadeOnDelete();
            $table->string('destination');
            $table->boolean('keep_copy')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('mail_mailbox_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_forwarding_rules');
    }
};
