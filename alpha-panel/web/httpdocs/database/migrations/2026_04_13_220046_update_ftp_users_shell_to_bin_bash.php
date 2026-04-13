<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ftp_users')->update(['shell' => '/bin/bash']);

        Schema::table('ftp_users', function (Blueprint $table) {
            $table->string('shell')->default('/bin/bash')->change();
        });
    }

    public function down(): void
    {
        DB::table('ftp_users')->update(['shell' => '/bin/false']);

        Schema::table('ftp_users', function (Blueprint $table) {
            $table->string('shell')->default('/bin/false')->change();
        });
    }
};
