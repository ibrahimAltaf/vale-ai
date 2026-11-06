<?php

declare(strict_types=1);

namespace Marketplace\Tests\Platform;

use Marketplace\Platform\Api\ApiServer;
use Marketplace\Platform\Api\HttpGuard;
use Marketplace\Platform\Api\RouteMap;
use Marketplace\Platform\Mangopay\MemoryGateway;
use Marketplace\Platform\Platform;
use PHPUnit\Framework\TestCase;

final class SecurityRegressionTest extends TestCase
{
    private string $directory;

    private Platform $platform;

    private ApiServer $api;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required. Run php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit.');
        }
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp-sec-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->platform = Platform::open(
            $this->directory . DIRECTORY_SEPARATOR . 'shop.sqlite',
            new MemoryGateway(),
            'admin@example.com',
            password_hash('admin-test-password', PASSWORD_DEFAULT),
            $this->directory . DIRECTORY_SEPARATOR . 'invoices',
        );
        $this->api = new ApiServer($this->platform);
    }

    protected function tearDown(): void
    {
        unset($this->api, $this->platform);
        gc_collect_cycles();
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (scandir($this->directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $this->directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? rmdir($path) : @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function testEveryProtectedRouteRejectsAnonymousCallers(): void
    {
        foreach (RouteMap::routes() as $route) {
            $path = preg_replace('/\{[A-Za-z]+\}/', '1', $route['path']) ?? $route['path'];
            $response = $this->api->handle($route['method'], $path, [], [], [], 'anon');
            if ($route['roles'] === []) {
                self::assertNotSame(401, $response['status'], $route['path']);
                continue;
            }
            self::assertSame(401, $response['status'], $route['method'] . ' ' . $route['path']);
            self::assertSame('unauthorized', $response['body']['error']['code']);
        }
    }

    public function testFailedPaymentReleasesTheLastUnit(): void
    {
        $vendor = $this->vendor();
        $first = $this->shopper('first@example.com');
        $order = $this->checkout($first);
        $failed = $this->api->handle('POST', '/api/v1/payments', [], [
            'order_id' => $order['id'],
            'card_id' => 'card_fail',
            'return_url' => 'https://shop.example/return',
        ], $this->bearer($first) + ['Idempotency-Key' => 'pay-fail-sec-1'], 'first');
        self::assertSame(402, $failed['status']);
        $second = $this->shopper('second@example.com');
        $again = $this->checkout($second);
        self::assertSame('reserved', $again['status']);
        unset($vendor);
    }

    public function testPaidReservationSurvivesExpiryAndBlocksASecondSale(): void
    {
        $vendor = $this->vendor();
        $shopper = $this->shopper('paid@example.com');
        $order = $this->checkout($shopper);
        $paid = $this->api->handle('POST', '/api/v1/payments', [], [
            'order_id' => $order['id'],
            'card_id' => 'card_ok',
            'return_url' => 'https://shop.example/return',
        ], $this->bearer($shopper) + ['Idempotency-Key' => 'pay-ok-sec-1'], 'paid');
        self::assertSame(201, $paid['status']);
        $released = $this->platform->releaseExpired(time() + 7200);
        self::assertSame(0, $released);
        $other = $this->shopper('late@example.com');
        $denied = $this->api->handle('POST', '/api/v1/checkout', [], [
            'postcode' => '10115',
            'lines' => [['product_id' => 50, 'quantity' => 1]],
        ], $this->bearer($other) + ['Idempotency-Key' => 'checkout-late'], 'late');
        self::assertSame(409, $denied['status']);
        unset($vendor);
    }

    public function testPaymentAfterTheHoldExpiresDoesNotChargeWithoutStock(): void
    {
        $vendor = $this->vendor();
        $first = $this->shopper('hold@example.com');
        $order = $this->checkout($first);
        $released = $this->platform->releaseExpired(time() + 7200);
        self::assertSame(1, $released);
        $second = $this->shopper('buyer@example.com');
        $this->checkout($second);
        $blocked = $this->api->handle('POST', '/api/v1/payments', [], [
            'order_id' => $order['id'],
            'card_id' => 'card_ok',
            'return_url' => 'https://shop.example/return',
        ], $this->bearer($first) + ['Idempotency-Key' => 'pay-expired-1'], 'hold');
        self::assertSame(409, $blocked['status']);
        self::assertSame('insufficient_stock', $blocked['body']['error']['code']);
        $loaded = $this->api->handle('GET', '/api/v1/orders/' . $order['id'], [], [], $this->bearer($first), 'hold');
        self::assertSame('unpaid', $loaded['body']['data']['payment_status']);
        unset($vendor);
    }

    public function testLogoutAndSuspensionRevokeAccess(): void
    {
        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $this->api->handle('POST', '/api/v1/auth/register/vendor', [], [
            'name' => 'Revoked Goods',
            'email' => 'revoked@example.com',
            'password' => 'vendor-password-1',
            'code' => 'V',
        ], [], 'revoked');
        $vendors = $this->api->handle('GET', '/api/v1/admin/vendors', [], [], $this->bearer($admin), 'admin');
        $vendorId = (int) $vendors['body']['data'][0]['id'];
        $this->api->handle('POST', '/api/v1/admin/vendors/' . $vendorId . '/approve', [], [], $this->bearer($admin), 'admin');
        $vendor = $this->login('vendor', 'revoked@example.com', 'vendor-password-1');
        $loggedOut = $this->api->handle('POST', '/api/v1/auth/logout', [], [], $this->bearer($vendor), 'vendor');
        self::assertSame(200, $loggedOut['status']);
        $denied = $this->api->handle('GET', '/api/v1/vendors/me', [], [], $this->bearer($vendor), 'vendor');
        self::assertSame(401, $denied['status']);

        $again = $this->login('vendor', 'revoked@example.com', 'vendor-password-1');
        $this->api->handle('POST', '/api/v1/admin/vendors/' . $vendorId . '/suspend', [], [], $this->bearer($admin), 'admin');
        $suspended = $this->api->handle('GET', '/api/v1/vendors/me', [], [], $this->bearer($again), 'vendor');
        self::assertSame(401, $suspended['status']);
    }

    public function testVendorCannotAssignCommissionOrReadAnotherVendorsExport(): void
    {
        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $this->api->handle('POST', '/api/v1/auth/register/vendor', [], [
            'name' => 'Owner Goods',
            'email' => 'owner@example.com',
            'password' => 'vendor-password-1',
            'code' => 'W',
            'commission_percent' => '1.00',
            'status' => 'active',
        ], [], 'owner');
        $rejected = $this->api->handle('POST', '/api/v1/auth/register/vendor', [], [
            'name' => 'Owner Goods',
            'email' => 'owner@example.com',
            'password' => 'vendor-password-1',
            'code' => 'W',
            'commission_percent' => '1.00',
        ], [], 'owner-b');
        self::assertSame(422, $rejected['status']);
        self::assertSame('unexpected_field', $rejected['body']['error']['code']);

        $this->api->handle('POST', '/api/v1/auth/register/vendor', [], [
            'name' => 'Owner Goods',
            'email' => 'owner@example.com',
            'password' => 'vendor-password-1',
            'code' => 'W',
        ], [], 'owner-c');
        $vendors = $this->api->handle('GET', '/api/v1/admin/vendors', [], [], $this->bearer($admin), 'admin');
        $vendorId = (int) $vendors['body']['data'][0]['id'];
        $this->api->handle('POST', '/api/v1/admin/vendors/' . $vendorId . '/approve', [], [], $this->bearer($admin), 'admin');
        $owner = $this->login('vendor', 'owner@example.com', 'vendor-password-1');
        $profile = $this->api->handle('PUT', '/api/v1/vendors/me', [], [
            'name' => 'Owner Goods',
            'commission_percent' => '1.00',
        ], $this->bearer($owner), 'owner');
        self::assertSame(422, $profile['status']);
        $me = $this->api->handle('GET', '/api/v1/vendors/me', [], [], $this->bearer($owner), 'owner');
        self::assertSame('10.00', $me['body']['data']['commission_percent']);

        $this->api->handle('POST', '/api/v1/vendors/me/offers', [], [
            'product_id' => 9,
            'sku' => 'FORMULA-1',
            'title' => '=1+1',
            'price_cents' => 100,
            'stock' => 1,
        ], $this->bearer($owner), 'owner');
        $export = $this->api->handle('GET', '/api/v1/vendors/me/exports/offers', [], [], $this->bearer($owner), 'owner');
        self::assertStringContainsString("'=1+1", $export['body']['data']['content']);

        $this->api->handle('POST', '/api/v1/auth/register/vendor', [], [
            'name' => 'Other Isolated',
            'email' => 'isolated@example.com',
            'password' => 'vendor-password-1',
            'code' => 'Z',
        ], [], 'other');
        $rows = $this->api->handle('GET', '/api/v1/admin/vendors', [], [], $this->bearer($admin), 'admin');
        $otherId = 0;
        foreach ($rows['body']['data'] as $row) {
            if ($row['code'] === 'Z') {
                $otherId = (int) $row['id'];
            }
        }
        $this->api->handle('POST', '/api/v1/admin/vendors/' . $otherId . '/approve', [], [], $this->bearer($admin), 'admin');
        $other = $this->login('vendor', 'isolated@example.com', 'vendor-password-1');
        $hidden = $this->api->handle('GET', '/api/v1/vendors/me/imports', [], [], $this->bearer($other), 'other');
        self::assertSame([], $hidden['body']['data']['imports']);
        $orders = $this->api->handle('GET', '/api/v1/vendors/me/orders', [], [], $this->bearer($other), 'other');
        self::assertSame([], $orders['body']['data']);
    }

    public function testMaintenanceDropsExpiredRateLimitRowsAndKeepsRecentOnes(): void
    {
        $now = time();
        for ($i = 0; $i < 120; $i++) {
            $this->platform->hit('expired-token', $now - 90000);
            $this->platform->hit('fresh-token', $now - 10);
        }
        self::assertTrue($this->platform->tooMany('expired-token', $now - 89990));
        $this->platform->runMaintenance($now);
        self::assertFalse($this->platform->tooMany('expired-token', $now));
        self::assertTrue($this->platform->tooMany('fresh-token', $now));
    }

    public function testMalformedJsonAndPathParametersAreRejected(): void
    {
        $headers = HttpGuard::headers('abc123');
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertSame("default-src 'none'", $headers['Content-Security-Policy']);
        self::assertSame('abc123', $headers['X-Request-Id']);

        try {
            HttpGuard::jsonBody('{', 'application/json', 'POST');
            self::fail('Malformed JSON was accepted.');
        } catch (\Marketplace\Platform\PlatformException $exception) {
            self::assertSame(400, $exception->status);
            self::assertSame('invalid_json', $exception->errorCode);
        }
        try {
            HttpGuard::jsonBody('{"a":1}', 'text/plain', 'POST');
            self::fail('A non-JSON body was accepted.');
        } catch (\Marketplace\Platform\PlatformException $exception) {
            self::assertSame(415, $exception->status);
        }

        $shopper = $this->shopper('path@example.com');
        $bad = $this->api->handle('GET', '/api/v1/orders/not-a-number', [], [], $this->bearer($shopper), 'path');
        self::assertSame(422, $bad['status']);
    }

    /**
     * @return array{token: string, role: string, subject_id: int}
     */
    private function vendor(): array
    {
        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $this->api->handle('POST', '/api/v1/auth/register/vendor', [], [
            'name' => 'Stock Goods',
            'email' => 'stock@example.com',
            'password' => 'vendor-password-1',
            'code' => 'S',
        ], [], 'stock-vendor');
        $vendors = $this->api->handle('GET', '/api/v1/admin/vendors', [], [], $this->bearer($admin), 'admin');
        $id = (int) $vendors['body']['data'][0]['id'];
        $this->api->handle('POST', '/api/v1/admin/vendors/' . $id . '/approve', [], [], $this->bearer($admin), 'admin');
        $token = $this->login('vendor', 'stock@example.com', 'vendor-password-1');
        $this->api->handle('POST', '/api/v1/vendors/me/shipping-rules', [], [
            'name' => 'Free',
            'base_cents' => 0,
            'free_threshold_cents' => null,
            'surcharge_cents_per_kg' => 0,
            'postcode_prefixes' => [],
        ], $this->bearer($token), 'stock-ship');
        $this->api->handle('POST', '/api/v1/vendors/me/offers', [], [
            'product_id' => 50,
            'sku' => 'LAST-1',
            'title' => 'Last unit',
            'price_cents' => 1000,
            'discount' => '0',
            'stock' => 1,
            'weight_grams' => 0,
        ], $this->bearer($token), 'stock-offer');
        $this->api->handle('POST', '/api/v1/vendors/me/mangopay/identity', [], [
            'legal_type' => 'natural',
            'first_name' => 'Lena',
            'last_name' => 'Vogel',
            'country' => 'DE',
            'nationality' => 'DE',
            'birthday' => '1990-01-02',
        ], $this->bearer($token), 'stock-kyc');

        return $token;
    }

    /**
     * @return array{token: string, role: string, subject_id: int}
     */
    private function shopper(string $email): array
    {
        $this->api->handle('POST', '/api/v1/auth/register/customer', [], [
            'email' => $email,
            'password' => 'shopper-password-1',
            'first_name' => 'Nora',
            'last_name' => 'Keller',
        ], [], $email);
        return $this->login('shopper', $email, 'shopper-password-1');
    }

    /**
     * @param array{token: string, subject_id: int} $shopper
     * @return array<string, mixed>
     */
    private function checkout(array $shopper): array
    {
        $response = $this->api->handle('POST', '/api/v1/checkout', [], [
            'postcode' => '10115',
            'lines' => [['product_id' => 50, 'quantity' => 1]],
        ], $this->bearer($shopper) + ['Idempotency-Key' => 'checkout-' . $shopper['subject_id']], 'checkout-' . $shopper['subject_id']);
        self::assertSame(201, $response['status'], json_encode($response['body']));

        return $response['body']['data'];
    }

    /**
     * @return array{token: string, role: string, subject_id: int}
     */
    private function login(string $role, string $email, string $password): array
    {
        $response = $this->api->handle('POST', '/api/v1/auth/login', [], [
            'role' => $role,
            'email' => $email,
            'password' => $password,
        ], [], $email . '-login');
        self::assertSame(200, $response['status'], json_encode($response['body']));

        return $response['body']['data'];
    }

    /**
     * @param array{token: string} $session
     * @return array<string, string>
     */
    private function bearer(array $session): array
    {
        return ['Authorization' => 'Bearer ' . $session['token']];
    }
}
