<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 20);
            $table->string('action', 30);
            $table->string('protocol', 10)->default('all');
            $table->string('source', 45)->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('comment', 255)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['chain', 'position']);
        });

        Schema::create('firewall_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 10)->unique();
            $table->string('policy', 10)->default('ACCEPT');
            $table->timestamps();
        });

        DB::table('firewall_policies')->insert([
            ['chain' => 'INPUT', 'policy' => 'ACCEPT', 'created_at' => now(), 'updated_at' => now()],
            ['chain' => 'OUTPUT', 'policy' => 'ACCEPT', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_rules');
        Schema::dropIfExists('firewall_policies');
    }
};
