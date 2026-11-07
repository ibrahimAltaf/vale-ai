<?php

declare(strict_types=1);

/**
 * Dump or restore marketplace tables only.
 * Restore onto the shop database named prestashop is refused.
 * This script does not delete orders or demo rows.
 */

$database = getenv('MARKETPLACE_DB_NAME') ?: '';
$command = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Set MARKETPLACE_DB_NAME to the target database.\n");
    exit(1);
}
if (!in_array($command, ['dump', 'restore'], true)) {
    fwrite(STDERR, "Usage: php bin/backup-marketplace.php dump|restore\n");
    exit(1);
}
if ($command === 'restore' && $database === 'prestashop') {
    fwrite(STDERR, "Refusing to restore onto the shop database.\n");
    exit(1);
}
if ($command === 'dump' && $database === 'prestashop' && getenv('MARKETPLACE_BACKUP_ALLOW_SHOP') !== '1') {
    fwrite(STDERR, "Refusing to dump the shop database unless MARKETPLACE_BACKUP_ALLOW_SHOP=1 is set. Demo data is not modified.\n");
    exit(1);
}

$tables = [
    'marketplace_vendor',
    'marketplace_shipping_rule',
    'marketplace_vendor_offer',
    'marketplace_vendor_stock',
    'marketplace_shopper',
    'marketplace_customer_link',
    'marketplace_order',
    'marketplace_vendor_order',
    'marketplace_vendor_order_line',
    'marketplace_stock_reservation',
    'marketplace_payment',
    'marketplace_refund',
    'marketplace_commission_ledger',
    'marketplace_invoice',
    'marketplace_audit_log',
];
$prefix = getenv('MARKETPLACE_DB_PREFIX') ?: 'ps_';
$prefixed = array_map(static fn (string $table): string => $prefix . $table, $tables);
$host = getenv('MARKETPLACE_DB_HOST') ?: 'db';
$user = getenv('MARKETPLACE_DB_USER') ?: 'prestashop';
$password = getenv('MARKETPLACE_DB_PASSWORD') ?: '';
if ($password === '') {
    fwrite(STDERR, "Set MARKETPLACE_DB_PASSWORD outside the command line history when you run a real dump.\n");
    exit(1);
}
$file = getenv('MARKETPLACE_BACKUP_FILE') ?: '';
if ($file === '' || str_contains($file, '..')) {
    fwrite(STDERR, "Set MARKETPLACE_BACKUP_FILE to a path outside the shop database volume.\n");
    exit(1);
}

$quoted = implode(' ', array_map(static fn (string $table): string => escapeshellarg($table), $prefixed));
if ($command === 'dump') {
    $shell = 'mysqldump --single-transaction --no-tablespaces -h ' . escapeshellarg($host)
        . ' -u ' . escapeshellarg($user) . ' ' . escapeshellarg($database) . ' ' . $quoted
        . ' > ' . escapeshellarg($file);
} else {
    $shell = 'mysql -h ' . escapeshellarg($host) . ' -u ' . escapeshellarg($user) . ' ' . escapeshellarg($database)
        . ' < ' . escapeshellarg($file);
}
putenv('MYSQL_PWD=' . $password);
passthru($shell, $exit);
exit($exit);
