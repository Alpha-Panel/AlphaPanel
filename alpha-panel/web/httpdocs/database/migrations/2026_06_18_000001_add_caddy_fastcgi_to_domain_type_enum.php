<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE domains MODIFY COLUMN `type` ENUM('apache_reverse_proxy','caddy_web_server','caddy_fastcgi') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE domains MODIFY COLUMN `type` ENUM('apache_reverse_proxy','caddy_web_server') NOT NULL");
    }
};
