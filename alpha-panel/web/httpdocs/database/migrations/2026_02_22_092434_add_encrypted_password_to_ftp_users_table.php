<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ftp_users', function (Blueprint $table) {
            $table->text('encrypted_password')->nullable()->after('home_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ftp_users', function (Blueprint $table) {
            $table->dropColumn('encrypted_password');
        });
    }
};
