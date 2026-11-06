<?php

declare(strict_types=1);

/**
 * cPanel-compatible marketplace cron.
 *
 * Example crontab, every five minutes:
 * php /home/USER/public_html/bin/marketplace-cron.php
 *
 * Required environment:
 *   MARKETPLACE_DB_DSN
 *   MARKETPLACE_DB_USER
 *   MARKETPLACE_DB_PASSWORD
 * Optional:
 *   MARKETPLACE_DB_PREFIX   default ps_
 *   MARKETPLACE_INVOICE_DIR
 */

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$moduleAutoloads = [
    dirname(__DIR__) . '/modules/marketplacecore/src/Autoload.php',
    '/var/www/html/modules/marketplacecore/src/Autoload.php',
];
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    foreach ($moduleAutoloads as $moduleAutoload) {
        if (is_file($moduleAutoload)) {
            require_once $moduleAutoload;
            Marketplace\Core\Autoload::register();
            break;
        }
    }
}
if (!class_exists(Marketplace\Platform\Platform::class)) {
    fwrite(STDERR, "Marketplace autoload was not found. Run composer install, or place this script in the project bin directory.\n");
    exit(1);
}

use Marketplace\Platform\Mangopay\HttpGateway;
use Marketplace\Platform\Platform;

$dsn = getenv('MARKETPLACE_DB_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "MARKETPLACE_DB_DSN is not set.\n");
    exit(1);
}

$user = getenv('MARKETPLACE_DB_USER');
$password = getenv('MARKETPLACE_DB_PASSWORD');
$prefix = getenv('MARKETPLACE_DB_PREFIX');
$directory = getenv('MARKETPLACE_INVOICE_DIR');
$pdo = new PDO(
    $dsn,
    is_string($user) ? $user : '',
    is_string($password) ? $password : '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$platform = Platform::connect(
    $pdo,
    is_string($prefix) && $prefix !== '' ? $prefix : 'ps_',
    HttpGateway::fromEnvironment(),
    is_string($directory) && $directory !== '' ? $directory : sys_get_temp_dir() . '/marketplace-invoices',
);
echo json_encode($platform->runMaintenance(time()), JSON_UNESCAPED_SLASHES) . "\n";
