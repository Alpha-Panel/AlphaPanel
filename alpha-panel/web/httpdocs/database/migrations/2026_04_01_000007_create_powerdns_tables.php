<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PowerDNS native MySQL schema (gmysql backend).
 *
 * Tables are created in a dedicated 'powerdns' database so PowerDNS
 * uses its default table names (domains, records, supermasters, domainmetadata).
 *
 * @see https://doc.powerdns.com/authoritative/backends/generic-mysql.html
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE DATABASE IF NOT EXISTS `powerdns`');

        DB::statement('
            CREATE TABLE IF NOT EXISTS `powerdns`.`domains` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL UNIQUE,
                `master` VARCHAR(128) DEFAULT NULL,
                `last_check` INT UNSIGNED DEFAULT NULL,
                `type` VARCHAR(8) NOT NULL DEFAULT \'NATIVE\',
                `notified_serial` INT UNSIGNED DEFAULT NULL,
                `account` VARCHAR(40) DEFAULT NULL,
                `catalog` VARCHAR(255) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS `powerdns`.`records` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `domain_id` BIGINT UNSIGNED DEFAULT NULL,
                `name` VARCHAR(255) DEFAULT NULL,
                `type` VARCHAR(10) DEFAULT NULL,
                `content` TEXT DEFAULT NULL,
                `ttl` INT UNSIGNED DEFAULT NULL,
                `prio` SMALLINT UNSIGNED DEFAULT NULL,
                `disabled` TINYINT(1) DEFAULT 0,
                `ordername` VARCHAR(255) DEFAULT NULL,
                `auth` TINYINT(1) DEFAULT 1,
                INDEX `domain_id_idx` (`domain_id`),
                INDEX `name_idx` (`name`),
                INDEX `name_type_idx` (`name`, `type`),
                INDEX `ordername_idx` (`ordername`),
                FOREIGN KEY (`domain_id`) REFERENCES `powerdns`.`domains`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS `powerdns`.`supermasters` (
                `ip` VARCHAR(64) NOT NULL,
                `nameserver` VARCHAR(255) NOT NULL,
                `account` VARCHAR(40) NOT NULL,
                PRIMARY KEY (`ip`, `nameserver`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS `powerdns`.`domainmetadata` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `domain_id` BIGINT UNSIGNED NOT NULL,
                `kind` VARCHAR(32) DEFAULT NULL,
                `content` TEXT DEFAULT NULL,
                INDEX `domain_id_idx` (`domain_id`),
                FOREIGN KEY (`domain_id`) REFERENCES `powerdns`.`domains`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `powerdns`.`domainmetadata`');
        DB::statement('DROP TABLE IF EXISTS `powerdns`.`supermasters`');
        DB::statement('DROP TABLE IF EXISTS `powerdns`.`records`');
        DB::statement('DROP TABLE IF EXISTS `powerdns`.`domains`');
        DB::statement('DROP DATABASE IF EXISTS `powerdns`');
    }
};
