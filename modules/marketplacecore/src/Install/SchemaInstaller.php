<?php

declare(strict_types=1);

namespace Marketplace\Core\Install;

use Marketplace\Core\Database\Connection;
use Marketplace\Core\Repository\VendorRepository;
use Marketplace\Core\Seed\DemoCatalog;

final class SchemaInstaller
{
    private static bool $upgraded = false;

    public function install(): void
    {
        $this->run('install.sql');
    }

    public function upgrade(): void
    {
        if (self::$upgraded || !defined('_DB_PREFIX_')) {
            return;
        }
        $db = new Connection();
        $this->column($db, 'marketplace_order', 'id_customer', 'INT UNSIGNED NULL');
        $this->column($db, 'marketplace_order', 'payment_method', 'VARCHAR(64) NULL');
        $this->column($db, 'marketplace_order', 'payment_status', "VARCHAR(32) NOT NULL DEFAULT 'recorded'");
        $this->column($db, 'marketplace_vendor_order', 'id_order', 'INT UNSIGNED NULL');
        $this->column($db, 'marketplace_vendor_order', 'shop_reference', 'VARCHAR(64) NULL');
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . Connection::table('marketplace_password_reset') . ' (
              `id_marketplace_password_reset` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `id_marketplace_vendor` INT UNSIGNED NOT NULL,
              `token_hash` CHAR(64) NOT NULL,
              `expires_at` DATETIME NOT NULL,
              `date_add` DATETIME NOT NULL,
              PRIMARY KEY (`id_marketplace_password_reset`),
              UNIQUE KEY `mp_password_reset_token` (`token_hash`),
              KEY `mp_password_reset_vendor` (`id_marketplace_vendor`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $vendors = new VendorRepository($db);
        foreach (DemoCatalog::extraShippingRules() as $rule) {
            $vendor = $db->one(
                'SELECT `id_marketplace_vendor` FROM ' . Connection::table('marketplace_vendor')
                . ' WHERE `code` = ' . Connection::quote($rule['vendor_code'])
            );
            if ($vendor === null) {
                continue;
            }
            $vendors->saveShipping(
                (int) $vendor['id_marketplace_vendor'],
                $rule['name'],
                $rule['base_cents'],
                $rule['free_threshold_cents'],
                $rule['surcharge_cents_per_kg'],
                implode(',', $rule['postcode_prefixes']),
            );
        }
        self::$upgraded = true;
    }

    private function column(Connection $db, string $table, string $column, string $definition): void
    {
        $found = (int) $db->value(
            'SELECT COUNT(*) FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = '
            . Connection::quote(_DB_PREFIX_ . $table) . ' AND `COLUMN_NAME` = ' . Connection::quote($column)
        );
        if ($found > 0) {
            return;
        }
        $db->exec('ALTER TABLE ' . Connection::table($table) . ' ADD COLUMN `' . $column . '` ' . $definition);
    }

    public function uninstall(): void
    {
        $this->run('uninstall.sql');
    }

    private function run(string $filename): void
    {
        $path = dirname(__DIR__, 2) . '/sql/' . $filename;
        $sql = (string) file_get_contents($path);
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        $connection = new Connection();

        foreach (preg_split('/;\s*[\r\n]+/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $connection->exec($statement);
        }
    }
}
