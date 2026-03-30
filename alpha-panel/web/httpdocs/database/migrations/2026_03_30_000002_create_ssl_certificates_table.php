<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ssl_certificates', function (Blueprint $table): void {
            $table->string('common_name', 255)->nullable()->change();

            // Replace file path columns with encrypted PEM content columns.
            // DB becomes the source of truth; files are written to disk on activation.
            $table->dropColumn(['cert_path', 'key_path', 'ca_bundle_path', 'csr_path']);
        });

        Schema::table('ssl_certificates', function (Blueprint $table): void {
            $table->text('private_key_pem')->nullable()->after('san_domains');
            $table->text('certificate_pem')->nullable()->after('private_key_pem');
            $table->text('ca_bundle_pem')->nullable()->after('certificate_pem');
            $table->text('csr_pem')->nullable()->after('ca_bundle_pem');
        });
    }

    public function down(): void
    {
        Schema::table('ssl_certificates', function (Blueprint $table): void {
            $table->dropColumn(['private_key_pem', 'certificate_pem', 'ca_bundle_pem', 'csr_pem']);
        });

        Schema::table('ssl_certificates', function (Blueprint $table): void {
            $table->string('common_name', 255)->nullable(false)->change();
            $table->string('cert_path', 500)->nullable()->after('san_domains');
            $table->string('key_path', 500)->after('cert_path');
            $table->string('ca_bundle_path', 500)->nullable()->after('key_path');
            $table->string('csr_path', 500)->nullable()->after('ca_bundle_path');
        });
    }
};
