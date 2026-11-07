<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;
use Marketplace\Platform\Api\ApiServer;
use Marketplace\Platform\Api\HttpGuard;
use Marketplace\Platform\Mangopay\HttpGateway;
use Marketplace\Platform\Platform;
use Marketplace\Platform\PlatformException;
use Marketplace\Platform\PrestaShopNativeOrders;

class MarketplaceplatformEntryModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        Autoload::register();
        $requestId = bin2hex(random_bytes(8));
        $result = $this->dispatch($requestId);
        http_response_code($result['status']);
        foreach (HttpGuard::headers($requestId) as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function dispatch(string $requestId): array
    {
        try {
            $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/api/v1/health'), PHP_URL_PATH);
            $marker = strpos($uri, '/api/v1');
            if ($marker !== false) {
                $uri = substr($uri, $marker);
            }
            $body = HttpGuard::jsonBody(
                (string) file_get_contents('php://input'),
                (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
                (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            );
            $headers = [];
            if (function_exists('getallheaders')) {
                foreach (getallheaders() ?: [] as $name => $value) {
                    $headers[(string) $name] = (string) $value;
                }
            }
            foreach ($_SERVER as $name => $value) {
                if (is_string($name) && str_starts_with($name, 'HTTP_') && is_string($value)) {
                    $headers[str_replace('_', '-', substr($name, 5))] = $value;
                }
            }
            $adminEmail = getenv('MARKETPLACE_ADMIN_EMAIL');
            $adminHash = getenv('MARKETPLACE_ADMIN_PASSWORD_HASH');
            $platform = Platform::connect(
                $this->pdo(),
                (string) _DB_PREFIX_,
                HttpGateway::fromEnvironment(),
                _PS_ROOT_DIR_ . '/var/marketplace-invoices',
                is_string($adminEmail) ? $adminEmail : '',
                is_string($adminHash) ? $adminHash : '',
            );
            $platform->setNativeOrderPort(new PrestaShopNativeOrders());

            return (new ApiServer($platform))->handle(
                (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                $uri,
                $_GET,
                $body,
                $headers,
                (string) ($_SERVER['REMOTE_ADDR'] ?? 'local'),
            );
        } catch (PlatformException $exception) {
            return [
                'status' => $exception->status,
                'body' => ['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]],
            ];
        } catch (\Throwable $exception) {
            error_log('marketplace api ' . $requestId . ': ' . $exception->getMessage());

            return [
                'status' => 500,
                'body' => ['error' => ['code' => 'internal_error', 'message' => 'The request could not be completed.']],
            ];
        }
    }

    private function pdo(): PDO
    {
        $host = (string) _DB_SERVER_;
        $port = defined('_DB_PORT_') ? (string) _DB_PORT_ : '';
        $dsn = 'mysql:host=' . $host . ';dbname=' . (string) _DB_NAME_ . ';charset=utf8mb4';
        if ($port !== '') {
            $dsn .= ';port=' . $port;
        }

        return new PDO($dsn, (string) _DB_USER_, (string) _DB_PASSWD_, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
