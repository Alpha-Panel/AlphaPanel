<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PowerDNS 4.9 requires a 'catalog' column on the domains table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = DB::select("SHOW COLUMNS FROM `powerdns`.`domains` LIKE 'catalog'");
        if (empty($columns)) {
            DB::statement("ALTER TABLE `powerdns`.`domains` ADD COLUMN `catalog` VARCHAR(255) DEFAULT NULL");
        }
    }

    public function down(): void
    {
        $columns = DB::select("SHOW COLUMNS FROM `powerdns`.`domains` LIKE 'catalog'");
        if (! empty($columns)) {
            DB::statement("ALTER TABLE `powerdns`.`domains` DROP COLUMN `catalog`");
        }
    }
};
