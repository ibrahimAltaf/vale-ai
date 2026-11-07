<?php

declare(strict_types=1);

require_once '/var/www/html/modules/marketplacecore/src/Autoload.php';
Marketplace\Core\Autoload::register();

use Marketplace\Platform\Mangopay\MemoryGateway;
use Marketplace\Platform\Platform;

$database = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_]+$/', $database) || $database === 'prestashop') {
    fwrite(STDERR, "Refusing the shop database.\n");
    exit(1);
}

$admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', 'prestashop', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
$admin->exec('CREATE DATABASE `' . $database . '`');
$admin->exec('GRANT ALL PRIVILEGES ON `' . $database . '`.* TO \'prestashop\'@\'%\'');
$admin->exec('FLUSH PRIVILEGES');
$pdo = new PDO('mysql:host=db;dbname=' . $database . ';charset=utf8mb4', 'prestashop', 'prestashop', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$core = (string) file_get_contents('/var/www/html/modules/marketplacecore/sql/install.sql');
foreach (preg_split('/;\s*[\r\n]+/', str_replace('PREFIX_', 'ps_', $core)) ?: [] as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}
Platform::connect(
    $pdo,
    'ps_',
    new MemoryGateway(),
    '/tmp/mp-prepare',
    'admin@example.com',
    password_hash('admin-test-password', PASSWORD_DEFAULT),
);
echo "ready\n";
