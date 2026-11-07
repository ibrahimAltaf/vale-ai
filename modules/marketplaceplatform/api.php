<?php

declare(strict_types=1);

/**
 * Shop API front that skips the storefront controller.
 * config.inc.php still boots PrestaShop. Payment and stock stay in MySQL.
 */

$root = dirname(__DIR__, 2);
require $root . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;
use Marketplace\Platform\Api\ApiServer;
use Marketplace\Platform\Api\HttpGuard;
use Marketplace\Platform\Mangopay\HttpGateway;
use Marketplace\Platform\Platform;
use Marketplace\Platform\PlatformException;
use Marketplace\Platform\PrestaShopNativeOrders;

Autoload::register();
$requestId = bin2hex(random_bytes(8));
foreach (HttpGuard::headers($requestId) as $name => $value) {
    header($name . ': ' . $value);
}

try {
    $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/api/v1/health'), PHP_URL_PATH);
    $marker = strpos($uri, '/api/v1');
    if ($marker !== false) {
        $uri = substr($uri, $marker);
    }
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $raw = file_get_contents('php://input');
    $body = HttpGuard::jsonBody(is_string($raw) ? $raw : '', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), $method);
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (is_string($name) && str_starts_with($name, 'HTTP_') && is_string($value)) {
            $headers[str_replace('_', '-', substr($name, 5))] = $value;
        }
    }
    $adminEmail = getenv('MARKETPLACE_ADMIN_EMAIL');
    $adminHash = getenv('MARKETPLACE_ADMIN_PASSWORD_HASH');
    $port = defined('_DB_PORT_') ? (string) _DB_PORT_ : '';
    $dsn = 'mysql:host=' . (string) _DB_SERVER_ . ';dbname=' . (string) _DB_NAME_ . ';charset=utf8mb4';
    if ($port !== '') {
        $dsn .= ';port=' . $port;
    }
    $pdo = new PDO($dsn, (string) _DB_USER_, (string) _DB_PASSWD_, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $platform = Platform::connect(
        $pdo,
        (string) _DB_PREFIX_,
        HttpGateway::fromEnvironment(),
        _PS_ROOT_DIR_ . '/var/marketplace-invoices',
        is_string($adminEmail) ? $adminEmail : '',
        is_string($adminHash) ? $adminHash : '',
    );
    $platform->setNativeOrderPort(new PrestaShopNativeOrders());
    $result = (new ApiServer($platform))->handle($method, $uri, $_GET, $body, $headers, (string) ($_SERVER['REMOTE_ADDR'] ?? 'local'));
    http_response_code($result['status']);
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
} catch (PlatformException $exception) {
    http_response_code($exception->status);
    echo json_encode([
        'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('marketplace api ' . $requestId . ': ' . $exception->getMessage());
    http_response_code(500);
    echo '{"error":{"code":"internal_error","message":"The request could not be completed."}}';
}
