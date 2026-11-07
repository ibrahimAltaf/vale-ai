<?php

declare(strict_types=1);

/**
 * Local contract server for the generated Postman collection.
 * It uses a disposable SQLite file and never opens the shop database.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Marketplace\Platform\Api\ApiServer;
use Marketplace\Platform\Api\HttpGuard;
use Marketplace\Platform\Mangopay\MemoryGateway;
use Marketplace\Platform\Platform;
use Marketplace\Platform\PlatformException;

$root = dirname(__DIR__);
$directory = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'newman-api';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    http_response_code(500);
    echo '{"error":{"code":"internal_error","message":"The contract server could not start."}}';
    return;
}

static $api = null;
if (!$api instanceof ApiServer) {
    $platform = Platform::open(
        $directory . DIRECTORY_SEPARATOR . 'shop.sqlite',
        new MemoryGateway($directory . DIRECTORY_SEPARATOR . 'gateway.json'),
        'admin@example.com',
        password_hash('replace-with-12-plus', PASSWORD_DEFAULT),
        $directory . DIRECTORY_SEPARATOR . 'invoices',
    );
    try {
        seedContract($platform);
    } catch (PlatformException) {
        // The disposable database is already seeded. Later requests reuse it.
    }
    $api = new ApiServer($platform);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = file_get_contents('php://input');
$requestId = bin2hex(random_bytes(8));
foreach (HttpGuard::headers($requestId) as $name => $value) {
    header($name . ': ' . $value);
}

try {
    $body = HttpGuard::jsonBody(is_string($raw) ? $raw : '', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), $method);
    $headers = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }
    }
    $existing = array_change_key_case($headers);
    foreach ($_SERVER as $name => $value) {
        if (!is_string($name) || !str_starts_with($name, 'HTTP_') || !is_string($value) || $value === '') {
            continue;
        }
        $key = strtolower(str_replace('_', '-', substr($name, 5)));
        if (!isset($existing[$key]) || $existing[$key] === '') {
            $headers[$key] = $value;
            $existing[$key] = $value;
        }
    }
    $response = $api->handle($method, $path, $_GET, $body, $headers, 'contract');
    http_response_code($response['status']);
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES);
} catch (PlatformException $exception) {
    http_response_code($exception->status);
    echo json_encode([
        'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('marketplace contract ' . $requestId . ': ' . $exception->getMessage());
    http_response_code(500);
    echo '{"error":{"code":"internal_error","message":"The request could not be completed."}}';
}

function seedContract(Platform $platform): void
{
    $admin = ['role' => 'admin', 'id' => 1];
    $vendor = $platform->registerVendor([
        'name' => 'Example Goods',
        'email' => 'vendor@example.com',
        'password' => 'replace-with-12-plus',
        'code' => 'E',
    ]);
    $vendorId = (int) $vendor['id'];
    $platform->approveVendor($admin, $vendorId);
    $actor = ['role' => 'vendor', 'id' => $vendorId];
    $platform->saveShippingRule($actor, $vendorId, [
        'name' => 'Nationwide',
        'base_cents' => 0,
        'free_threshold_cents' => null,
        'surcharge_cents_per_kg' => 0,
        'postcode_prefixes' => [],
    ]);
    $platform->upsertOffer($actor, $vendorId, [
        'product_id' => 50,
        'sku' => 'SKU-E',
        'title' => 'Sample',
        'price_cents' => 10000,
        'discount' => '0',
        'stock' => 20,
        'weight_grams' => 0,
    ]);
    $platform->ensureIdentity($actor, $vendorId, [
        'legal_type' => 'natural',
        'first_name' => 'Lena',
        'last_name' => 'Vogel',
        'country' => 'DE',
        'nationality' => 'DE',
        'birthday' => '1990-01-02',
    ]);
    $platform->registerShopper([
        'email' => 'shopper@example.com',
        'password' => 'replace-with-12-plus',
        'first_name' => 'Nora',
        'last_name' => 'Keller',
    ]);
    $other = $platform->registerVendor([
        'name' => 'Other Goods',
        'email' => 'other-vendor@example.com',
        'password' => 'replace-with-12-plus',
        'code' => 'Z',
    ]);
    $platform->approveVendor($admin, (int) $other['id']);
}
