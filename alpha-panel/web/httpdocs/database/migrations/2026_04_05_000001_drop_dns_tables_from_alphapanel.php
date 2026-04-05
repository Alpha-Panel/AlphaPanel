<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DNS storage lives entirely in the `powerdns` database now.
        // These tables were created by legacy migrations 000005 / 000006
        // in the main AlphaPanel database and are no longer used.
        Schema::dropIfExists('dns_records');
        Schema::dropIfExists('dns_zones');
    }

    public function down(): void
    {
        // Intentionally empty — recreating these in AlphaPanel would
        // re-introduce the bug this migration resolves.
    }
};
