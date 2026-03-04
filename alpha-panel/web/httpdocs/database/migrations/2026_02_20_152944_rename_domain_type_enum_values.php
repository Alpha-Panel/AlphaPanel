<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE domains MODIFY COLUMN `type` ENUM('legacy','modern','apache_reverse_proxy','caddy_web_server') NOT NULL");
        DB::table('domains')->where('type', 'legacy')->update(['type' => 'apache_reverse_proxy']);
        DB::table('domains')->where('type', 'modern')->update(['type' => 'caddy_web_server']);
        DB::statement("ALTER TABLE domains MODIFY COLUMN `type` ENUM('apache_reverse_proxy','caddy_web_server') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE domains MODIFY COLUMN `type` ENUM('legacy','modern','apache_reverse_proxy','caddy_web_server') NOT NULL");
        DB::table('domains')->where('type', 'apache_reverse_proxy')->update(['type' => 'legacy']);
        DB::table('domains')->where('type', 'caddy_web_server')->update(['type' => 'modern']);
        DB::statement("ALTER TABLE domains MODIFY COLUMN `type` ENUM('legacy','modern') NOT NULL");
    }
};
