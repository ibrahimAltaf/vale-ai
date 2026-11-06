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
    '/tmp/mp-api-bench',
    'admin@example.com',
    password_hash('admin-test-password', PASSWORD_DEFAULT),
);
$api = new ApiServer($platform);
$admin = $api->handle('POST', '/api/v1/auth/login', [], ['role' => 'admin', 'email' => 'admin@example.com', 'password' => 'admin-test-password'], [], 'bench-admin');
$vendor = $api->handle('POST', '/api/v1/auth/login', [], ['role' => 'vendor', 'email' => 'race@example.com', 'password' => 'vendor-password-1'], [], 'bench-vendor');
$shopper = $api->handle('POST', '/api/v1/auth/login', [], ['role' => 'shopper', 'email' => 'race-shopper@example.com', 'password' => 'shopper-password-1'], [], 'bench-shopper');
$adminToken = ['Authorization' => 'Bearer ' . $admin['body']['data']['token']];
$vendorToken = ['Authorization' => 'Bearer ' . $vendor['body']['data']['token']];
$shopperToken = ['Authorization' => 'Bearer ' . $shopper['body']['data']['token']];
$calls = [
    ['health', 'GET', '/api/v1/health', [], []],
    ['offers', 'GET', '/api/v1/offers', [], $shopperToken],
    ['vendor profile', 'GET', '/api/v1/vendors/me', [], $vendorToken],
    ['admin vendors', 'GET', '/api/v1/admin/vendors', [], $adminToken],
    ['vendor orders', 'GET', '/api/v1/vendors/me/orders', [], $vendorToken],
    ['invalid login', 'POST', '/api/v1/auth/login', ['role' => 'admin'], []],
];
$report = [];
foreach ($calls as [$name, $method, $path, $body, $headers]) {
    $samples = [];
    $status = 0;
    for ($i = 0; $i < 30; $i++) {
        $started = hrtime(true);
        $response = $api->handle($method, $path, [], $body, $headers, 'bench-' . $i);
        $samples[] = (hrtime(true) - $started) / 1_000_000;
        $status = $response['status'];
    }
    sort($samples);
    $report[] = [
        'name' => $name,
        'status' => $status,
        'p50_ms' => $samples[14],
        'p95_ms' => $samples[28],
        'max_ms' => $samples[29],
    ];
}
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
