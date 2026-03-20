<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename columns to match ProFTPD's expected schema directly,
     * eliminating the need for a VIEW.
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS ftpusers');

        Schema::table('ftp_users', function (Blueprint $table) {
            $table->renameColumn('home_path', 'homedir');
            $table->renameColumn('password_hash', 'password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ftp_users', function (Blueprint $table) {
            $table->renameColumn('homedir', 'home_path');
            $table->renameColumn('password', 'password_hash');
        });

        DB::statement('
            CREATE OR REPLACE VIEW ftpusers AS
            SELECT
                username,
                password_hash as password,
                uid,
                uid as gid,
                home_path as homedir,
                shell
            FROM ftp_users
            WHERE password_hash IS NOT NULL
        ');
    }
};
