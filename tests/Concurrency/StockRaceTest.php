<?php

declare(strict_types=1);

namespace Marketplace\Tests\Concurrency;

use Marketplace\Platform\Api\ApiServer;
use Marketplace\Platform\Mangopay\MemoryGateway;
use Marketplace\Platform\Platform;
use PHPUnit\Framework\TestCase;

final class StockRaceTest extends TestCase
{
    public function testTwoProcessesCannotOversellTheLastUnit(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required. Run php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit.');
        }
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp-race-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $database = $directory . DIRECTORY_SEPARATOR . 'race.sqlite';
        $platform = Platform::open(
            $database,
            new MemoryGateway(),
            'admin@example.com',
            password_hash('admin-test-password', PASSWORD_DEFAULT),
            $directory . DIRECTORY_SEPARATOR . 'invoices',
        );
        $api = new ApiServer($platform);
        $admin = $this->login($api, 'admin', 'admin@example.com', 'admin-test-password');
        $api->handle('POST', '/api/v1/auth/register/vendor', [], [
            'name' => 'Race Goods',
            'email' => 'race@example.com',
            'password' => 'vendor-password-1',
            'code' => 'R',
        ], [], 'race-vendor');
        $vendors = $api->handle('GET', '/api/v1/admin/vendors', [], [], ['Authorization' => 'Bearer ' . $admin['token']], 'race-admin');
        $vendorId = (int) $vendors['body']['data'][0]['id'];
        $api->handle('POST', '/api/v1/admin/vendors/' . $vendorId . '/approve', [], [], ['Authorization' => 'Bearer ' . $admin['token']], 'race-admin');
        $vendor = $this->login($api, 'vendor', 'race@example.com', 'vendor-password-1');
        $api->handle('POST', '/api/v1/vendors/me/shipping-rules', [], [
            'name' => 'Free',
            'base_cents' => 0,
            'free_threshold_cents' => null,
            'surcharge_cents_per_kg' => 0,
            'postcode_prefixes' => [],
        ], ['Authorization' => 'Bearer ' . $vendor['token']], 'race-ship');
        $api->handle('POST', '/api/v1/vendors/me/offers', [], [
            'product_id' => 77,
            'sku' => 'RACE-1',
            'title' => 'Last unit',
            'price_cents' => 1000,
            'discount' => '0',
            'stock' => 1,
            'weight_grams' => 0,
        ], ['Authorization' => 'Bearer ' . $vendor['token']], 'race-offer');
        $api->handle('POST', '/api/v1/auth/register/customer', [], [
            'email' => 'race-shopper@example.com',
            'password' => 'shopper-password-1',
            'first_name' => 'Rae',
            'last_name' => 'Stock',
        ], [], 'race-shopper');
        $shopper = $this->login($api, 'shopper', 'race-shopper@example.com', 'shopper-password-1');
        unset($api, $platform);
        gc_collect_cycles();

        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Concurrency' . DIRECTORY_SEPARATOR . 'reserve-once.php';
        $results = [];
        $processes = [];
        foreach ([1, 2] as $index) {
            $command = [PHP_BINARY, '-d', 'extension=pdo_sqlite', $script, $database, (string) $shopper['subject_id'], '77'];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            $results[] = trim((string) stream_get_contents($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            self::assertSame('', trim((string) $error));
        }
        sort($results);
        self::assertSame(['NO', 'OK'], $results);

        $platform = Platform::open($database, new MemoryGateway(), 'admin@example.com', password_hash('admin-test-password', PASSWORD_DEFAULT), $directory . DIRECTORY_SEPARATOR . 'invoices');
        $api = new ApiServer($platform);
        $orders = $api->handle('GET', '/api/v1/orders', [], [], ['Authorization' => 'Bearer ' . $shopper['token']], 'race-read');
        self::assertSame(1, $orders['body']['meta']['total']);
        unset($api, $platform);
        gc_collect_cycles();
        $this->deleteTree($directory);
    }

    /**
     * @return array{token: string, subject_id: int}
     */
    private function login(ApiServer $api, string $role, string $email, string $password): array
    {
        $response = $api->handle('POST', '/api/v1/auth/login', [], [
            'role' => $role,
            'email' => $email,
            'password' => $password,
        ], [], $email . '-login');
        self::assertSame(200, $response['status'], json_encode($response['body']));

        return $response['body']['data'];
    }

    private function deleteTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }
        rmdir($directory);
    }
}
