<?php

declare(strict_types=1);

require_once '/var/www/html/modules/marketplacecore/src/Autoload.php';
Marketplace\Core\Autoload::register();

use Marketplace\Platform\Api\ApiServer;
use Marketplace\Platform\Mangopay\MemoryGateway;
use Marketplace\Platform\Platform;

$database = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_]+$/', $database) || $database === 'prestashop') {
    fwrite(STDERR, "Refusing the shop database.\n");
    exit(1);
}
$pdo = new PDO('mysql:host=db;dbname=' . $database . ';charset=utf8mb4', 'prestashop', 'prestashop', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$platform = Platform::connect(
    $pdo,
    'ps_',
    new MemoryGateway(),
    '/tmp/mp-race-invoices',
    'admin@example.com',
    password_hash('admin-test-password', PASSWORD_DEFAULT),
);
$api = new ApiServer($platform);
$admin = $api->handle('POST', '/api/v1/auth/login', [], [
    'role' => 'admin',
    'email' => 'admin@example.com',
    'password' => 'admin-test-password',
], [], 'admin');
$token = $admin['body']['data']['token'];
$bearer = ['Authorization' => 'Bearer ' . $token];
$api->handle('POST', '/api/v1/auth/register/vendor', [], [
    'name' => 'Race Goods',
    'email' => 'race@example.com',
    'password' => 'vendor-password-1',
    'code' => 'R',
], [], 'vendor');
$vendors = $api->handle('GET', '/api/v1/admin/vendors', [], [], $bearer, 'admin');
$vendorId = (int) $vendors['body']['data'][0]['id'];
$api->handle('POST', '/api/v1/admin/vendors/' . $vendorId . '/approve', [], [], $bearer, 'admin');
$vendor = $api->handle('POST', '/api/v1/auth/login', [], [
    'role' => 'vendor',
    'email' => 'race@example.com',
    'password' => 'vendor-password-1',
], [], 'vendor-login');
$vendorToken = ['Authorization' => 'Bearer ' . $vendor['body']['data']['token']];
$api->handle('POST', '/api/v1/vendors/me/shipping-rules', [], [
    'name' => 'Free',
    'base_cents' => 0,
    'free_threshold_cents' => null,
    'surcharge_cents_per_kg' => 0,
    'postcode_prefixes' => [],
], $vendorToken, 'ship');
$api->handle('POST', '/api/v1/vendors/me/offers', [], [
    'product_id' => 77,
    'sku' => 'RACE-1',
    'title' => 'Last unit',
    'price_cents' => 1000,
    'discount' => '0',
    'stock' => 1,
    'weight_grams' => 0,
], $vendorToken, 'offer');
$api->handle('POST', '/api/v1/auth/register/customer', [], [
    'email' => 'race-shopper@example.com',
    'password' => 'shopper-password-1',
    'first_name' => 'Rae',
    'last_name' => 'Stock',
], [], 'shopper');
$shopper = $api->handle('POST', '/api/v1/auth/login', [], [
    'role' => 'shopper',
    'email' => 'race-shopper@example.com',
    'password' => 'shopper-password-1',
], [], 'shopper-login');
$shopperId = (int) $shopper['body']['data']['subject_id'];
unset($api, $platform, $pdo);
gc_collect_cycles();

$child = '/var/www/html/modules/marketplaceplatform/bin/mysql-reserve.php';
$results = [];
$processes = [];
foreach ([1, 2] as $ignored) {
    $command = ['php', $child, $database, (string) $shopperId];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "Could not start a checkout process.\n");
        exit(1);
    }
    $processes[] = [$process, $pipes];
}
foreach ($processes as [$process, $pipes]) {
    $results[] = trim((string) stream_get_contents($pipes[1]));
    $error = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($error !== '') {
        fwrite(STDERR, $error . "\n");
        exit(1);
    }
}
sort($results);
$pdo = new PDO('mysql:host=db;dbname=' . $database . ';charset=utf8mb4', 'prestashop', 'prestashop');
$orders = (int) $pdo->query('SELECT COUNT(*) FROM ps_marketplace_order')->fetchColumn();
echo json_encode(['results' => $results, 'orders' => $orders], JSON_UNESCAPED_SLASHES) . "\n";
if ($results !== ['NO', 'OK'] || $orders !== 1) {
    exit(1);
}
