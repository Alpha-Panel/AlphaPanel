<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add new JSON columns
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->json('sources')->nullable()->after('protocol');
            $table->json('ports')->nullable()->after('sources');
        });

        // Migrate existing data: wrap single source/port into JSON arrays
        DB::table('firewall_rules')->orderBy('id')->each(function (object $row): void {
            DB::table('firewall_rules')->where('id', $row->id)->update([
                'sources' => $row->source !== null ? json_encode([$row->source]) : null,
                'ports' => $row->port !== null ? json_encode([(int) $row->port]) : null,
            ]);
        });

        // Drop old columns
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->dropColumn(['source', 'port']);
        });
    }

    public function down(): void
    {
        // Re-add old columns
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->string('source', 45)->nullable()->after('protocol');
            $table->unsignedSmallInteger('port')->nullable()->after('source');
        });

        // Migrate data back: extract first element from JSON arrays
        DB::table('firewall_rules')->orderBy('id')->each(function (object $row): void {
            $sources = $row->sources !== null ? json_decode($row->sources, true) : null;
            $ports = $row->ports !== null ? json_decode($row->ports, true) : null;

            DB::table('firewall_rules')->where('id', $row->id)->update([
                'source' => is_array($sources) && count($sources) > 0 ? $sources[0] : null,
                'port' => is_array($ports) && count($ports) > 0 ? $ports[0] : null,
            ]);
        });

        // Drop JSON columns
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->dropColumn(['sources', 'ports']);
        });
    }
};
