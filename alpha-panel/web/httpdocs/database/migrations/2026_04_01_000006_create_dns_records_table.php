<?php

use Illuminate\Database\Migrations\Migration;

/**
 * DNS tables now live in the `powerdns` database and are managed externally.
 * Kept as a no-op to preserve the historical migration sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
