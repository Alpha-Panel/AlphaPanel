<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ftp_users', function (Blueprint $table) {
            $table->string('password_hash')->nullable()->after('encrypted_password');
            $table->string('shell')->default('/bin/false')->after('uid');
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ftpusers');

        Schema::table('ftp_users', function (Blueprint $table) {
            $table->dropColumn(['password_hash', 'shell']);
        });
    }
};
