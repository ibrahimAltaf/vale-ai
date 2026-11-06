<?php

declare(strict_types=1);

require_once '/var/www/html/modules/marketplacecore/src/Autoload.php';
Marketplace\Core\Autoload::register();

use Marketplace\Platform\Mangopay\MemoryGateway;
use Marketplace\Platform\Platform;
use Marketplace\Platform\PlatformException;

$database = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_]+$/', $database) || $database === 'prestashop') {
    fwrite(STDERR, "Refusing the shop database.\n");
    exit(1);
}
$pdo = new PDO('mysql:host=db;dbname=' . $database . ';charset=utf8mb4', 'prestashop', 'prestashop', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$platform = Platform::connect($pdo, 'ps_', new MemoryGateway(), '/tmp/mp-race-invoices');
try {
    $platform->checkout(
        ['role' => 'shopper', 'id' => (int) $argv[2]],
        '10115',
        [['product_id' => 77, 'quantity' => 1]],
    );
    echo "OK\n";
} catch (PlatformException $exception) {
    if (in_array($exception->errorCode, ['insufficient_stock', 'infeasible'], true)) {
        echo "NO\n";
        exit(0);
    }
    fwrite(STDERR, $exception->errorCode . ' ' . $exception->getMessage() . "\n");
    echo "ERR\n";
    exit(1);
}
