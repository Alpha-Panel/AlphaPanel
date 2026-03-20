<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add gid column required by ProFTPD's SQLUserInfo directive.
     *
     * ProFTPD expects: username, password, uid, gid, homedir, shell
     * Default gid = uid (single-user groups).
     */
    public function up(): void
    {
        Schema::table('ftp_users', function (Blueprint $table) {
            $table->unsignedInteger('gid')->after('uid');
        });

        // Set gid = uid for existing rows
        DB::table('ftp_users')->whereNull('gid')->orWhere('gid', 0)->update([
            'gid' => DB::raw('uid'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ftp_users', function (Blueprint $table) {
            $table->dropColumn('gid');
        });
    }
};
