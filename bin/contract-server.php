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
        new MemoryGateway(),
        'admin@example.com',
        password_hash('replace-with-12-plus', PASSWORD_DEFAULT),
        $directory . DIRECTORY_SEPARATOR . 'invoices',
    );
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
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $response = $api->handle($method, $path, $_GET, $body, is_array($headers) ? $headers : [], 'contract');
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
