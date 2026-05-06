<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->foreignId('linked_domain_id')
                ->nullable()
                ->after('parent_domain_id')
                ->constrained('domains')
                ->nullOnDelete();

            $table->string('mode', 32)
                ->default('main')
                ->after('parent_domain_id')
                ->index();
        });

        DB::table('domains')
            ->whereNotNull('parent_domain_id')
            ->update(['mode' => 'subdomain']);

        DB::statement("ALTER TABLE domains ADD UNIQUE INDEX uniq_catchall ((CASE WHEN fqdn = '*' THEN 1 ELSE NULL END))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE domains DROP INDEX IF EXISTS uniq_catchall');

        Schema::table('domains', function (Blueprint $table): void {
            $table->dropForeign(['linked_domain_id']);
            $table->dropColumn('linked_domain_id');
            $table->dropColumn('mode');
        });
    }
};
