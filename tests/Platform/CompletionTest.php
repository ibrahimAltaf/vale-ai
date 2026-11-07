<?php

declare(strict_types=1);

namespace Marketplace\Tests\Platform;

use Marketplace\Platform\Api\ApiServer;
use Marketplace\Platform\Mangopay\MemoryGateway;
use Marketplace\Platform\NativeOrderPort;
use Marketplace\Platform\Platform;
use PHPUnit\Framework\TestCase;

final class CompletionTest extends TestCase
{
    private string $directory;

    private MemoryGateway $gateway;

    private Platform $platform;

    private ApiServer $api;

    private RecordingOrders $orders;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp-complete-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->gateway = new MemoryGateway();
        $this->platform = Platform::open(
            $this->directory . DIRECTORY_SEPARATOR . 'shop.sqlite',
            $this->gateway,
            'admin@example.com',
            password_hash('admin-test-password', PASSWORD_DEFAULT),
            $this->directory . DIRECTORY_SEPARATOR . 'invoices',
        );
        $this->orders = new RecordingOrders('T');
        $this->platform->setNativeOrderPort($this->orders);
        $this->platform->setExposeResetTokens(true);
        $this->api = new ApiServer($this->platform);
    }

    protected function tearDown(): void
    {
        unset($this->api, $this->platform, $this->orders);
        gc_collect_cycles();
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (scandir($this->directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $this->directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function testCustomerLinkRequiresACodeAndCreatesANativeOrderOnlyForTheLinkedCustomer(): void
    {
        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $vendor = $this->vendor($admin, 'link@example.com', 'T');
        $shopper = $this->shopper('linked@example.com');
        $other = $this->shopper('other-link@example.com');
        $challenge = $this->api->handle('POST', '/api/v1/customers/me/link-challenges', [], [
            'id_customer' => 42,
        ], $this->bearer($shopper), 'link');
        self::assertSame(201, $challenge['status'], json_encode($challenge['body']));
        $wrong = $this->api->handle('POST', '/api/v1/admin/customers/links', [], [
            'shopper_id' => (int) $shopper['subject_id'],
            'id_customer' => 42,
            'code' => '0000000000000000',
        ], $this->bearer($admin), 'bad-link');
        self::assertSame(403, $wrong['status']);
        $confirmed = $this->api->handle('POST', '/api/v1/admin/customers/links', [], [
            'shopper_id' => (int) $shopper['subject_id'],
            'id_customer' => 42,
            'code' => $challenge['body']['data']['code'],
        ], $this->bearer($admin), 'good-link');
        self::assertSame(200, $confirmed['status'], json_encode($confirmed['body']));
        self::assertSame(42, $confirmed['body']['data']['id_customer']);
        self::assertNotSame((int) $shopper['subject_id'], $confirmed['body']['data']['id_customer']);

        $second = $this->api->handle('POST', '/api/v1/customers/me/link-challenges', [], [
            'id_customer' => 42,
        ], $this->bearer($other), 'link-2');
        $taken = $this->api->handle('POST', '/api/v1/admin/customers/links', [], [
            'shopper_id' => (int) $other['subject_id'],
            'id_customer' => 42,
            'code' => $second['body']['data']['code'],
        ], $this->bearer($admin), 'taken');
        self::assertSame(409, $taken['status']);

        $order = $this->checkout($shopper, $vendor, 'linked');
        $paid = $this->pay($shopper, (int) $order['id'], 'card_ok', 'pay-link');
        self::assertSame(201, $paid['status'], json_encode($paid['body']));
        self::assertSame([[$order['reference'], 42]], $this->orders->calls);
        $loaded = $this->api->handle('GET', '/api/v1/orders/' . $order['id'], [], [], $this->bearer($shopper), 'order');
        self::assertSame(42, $loaded['body']['data']['id_customer']);
        self::assertSame(900, $loaded['body']['data']['vendor_orders'][0]['native_order_id']);

        $plain = $this->shopper('plain@example.com');
        $this->orders->calls = [];
        $secondOrder = $this->checkout($plain, $vendor, 'plain');
        $this->pay($plain, (int) $secondOrder['id'], 'card_ok', 'pay-plain');
        self::assertSame([], $this->orders->calls);
    }

    public function testPasswordResetReplacesOnlyTheConfirmedAccount(): void
    {
        $shopper = $this->shopper('reset@example.com');
        $missing = $this->api->handle('POST', '/api/v1/auth/password-reset', [], [
            'role' => 'shopper',
            'email' => 'missing@example.com',
        ], [], 'missing-reset');
        self::assertSame(200, $missing['status']);
        self::assertArrayNotHasKey('token', $missing['body']['data']);
        $requested = $this->api->handle('POST', '/api/v1/auth/password-reset', [], [
            'role' => 'shopper',
            'email' => 'reset@example.com',
        ], [], 'reset');
        self::assertSame(200, $requested['status']);
        $token = $requested['body']['data']['token'];
        $confirmed = $this->api->handle('POST', '/api/v1/auth/password-reset/confirm', [], [
            'role' => 'shopper',
            'email' => 'reset@example.com',
            'token' => $token,
            'password' => 'replacement-password-1',
        ], [], 'confirm-reset');
        self::assertSame(200, $confirmed['status'], json_encode($confirmed['body']));
        $old = $this->api->handle('POST', '/api/v1/auth/login', [], [
            'role' => 'shopper',
            'email' => 'reset@example.com',
            'password' => 'shopper-password-1',
        ], [], 'old-login');
        self::assertSame(401, $old['status']);
        $this->login('shopper', 'reset@example.com', 'replacement-password-1');
        self::assertNotSame('', $shopper['token']);
    }

    public function testVatIsSeparateFromNetAndAFullRefundRestocks(): void
    {
        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $vendor = $this->vendor($admin, 'vat@example.com', 'V');
        $vat = $this->api->handle('POST', '/api/v1/admin/vendors/' . $vendor['vendor_id'] . '/vat', [], [
            'vat_rate_percent' => '22.00',
        ], $this->bearer($admin), 'vat');
        self::assertSame(200, $vat['status'], json_encode($vat['body']));
        $shopper = $this->shopper('vat-shopper@example.com');
        $order = $this->checkout($shopper, $vendor, 'vat');
        self::assertSame(10000, $order['grand_cents']);
        $paid = $this->pay($shopper, (int) $order['id'], 'card_ok', 'pay-vat-ok');
        self::assertSame(201, $paid['status'], json_encode($paid['body']));
        $month = date('Y-m');
        $statement = $this->api->handle('GET', '/api/v1/vendors/me/statements', ['month' => $month], [], $this->bearer($vendor), 'statement');
        self::assertSame(200, $statement['status'], json_encode($statement['body']));
        self::assertSame(1000, $statement['body']['data']['commission_cents']);
        self::assertSame(220, $statement['body']['data']['vat_cents']);
        self::assertSame(9000, $statement['body']['data']['net_cents']);
        $csv = $this->api->handle('GET', '/api/v1/vendors/me/statements/export', ['month' => $month], [], $this->bearer($vendor), 'csv');
        self::assertStringContainsString('220', $csv['body']['data']['content']);
        $foreign = $this->api->handle('GET', '/api/v1/vendors/me/statements', ['month' => $month], [], $this->bearer($shopper), 'foreign-statement');
        self::assertSame(403, $foreign['status']);

        $refund = $this->api->handle('POST', '/api/v1/admin/refunds', [], [
            'order_id' => (int) $order['id'],
            'amount_cents' => 10000,
        ], $this->bearer($admin) + ['Idempotency-Key' => 'refund-full-vat'], 'refund-full');
        self::assertSame(201, $refund['status'], json_encode($refund['body']));
        $note = $this->api->handle('POST', '/api/v1/vendors/me/credit-notes', [], [
            'vendor_order_id' => (int) $order['vendor_orders'][0]['id'],
        ], $this->bearer($vendor), 'credit');
        self::assertSame(201, $note['status'], json_encode($note['body']));
        self::assertSame('credit_note', $note['body']['data']['kind']);
        $visible = $this->api->handle('GET', '/api/v1/invoices/' . $note['body']['data']['id'], [], [], $this->bearer($shopper), 'see-credit');
        self::assertSame(200, $visible['status'], json_encode($visible['body']));

        $again = $this->checkout($shopper, $vendor, 'after-refund');
        self::assertSame('reserved', $again['status']);
    }

    public function testSucceededWebhookWithoutStockDoesNotMarkTheOrderPaid(): void
    {
        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $vendor = $this->vendor($admin, 'conflict@example.com', 'C', 1);
        $first = $this->shopper('conflict-a@example.com');
        $second = $this->shopper('conflict-b@example.com');
        $order = $this->checkout($first, $vendor, 'conflict-a');
        $pending = $this->pay($first, (int) $order['id'], 'card_pending', 'pay-pending');
        self::assertSame(202, $pending['status'], json_encode($pending['body']));
        self::assertSame(1, $this->platform->releaseExpired(time() + 7200));
        $this->gateway->update((string) $pending['body']['data']['provider_id'], ['Status' => 'SUCCEEDED']);
        $taken = $this->checkout($second, $vendor, 'conflict-b');
        self::assertSame('reserved', $taken['status']);
        $hook = $this->api->handle('POST', '/api/v1/mangopay/webhooks', [], [
            'EventType' => 'PAYIN_NORMAL_SUCCEEDED',
            'RessourceId' => $pending['body']['data']['provider_id'],
        ], [], 'stock-hook');
        self::assertSame(200, $hook['status'], json_encode($hook['body']));
        $loaded = $this->api->handle('GET', '/api/v1/orders/' . $order['id'], [], [], $this->bearer($first), 'conflict-order');
        self::assertSame('unpaid', $loaded['body']['data']['payment_status']);
        $payment = $this->api->handle('GET', '/api/v1/payments/' . $pending['body']['data']['id'], [], [], $this->bearer($first), 'conflict-payment');
        self::assertSame('stock_conflict', $payment['body']['data']['settlement_status']);
    }

    public function testVendorSearchPaginationAndOfferFilterStayInsideTheCaller(): void
    {
        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $first = $this->vendor($admin, 'page-a@example.com', 'A');
        $second = $this->vendor($admin, 'page-b@example.com', 'B');
        $this->vendor($admin, 'page-c@example.com', 'D');
        $page = $this->api->handle('GET', '/api/v1/admin/vendors', ['page' => 2, 'per_page' => 1], [], $this->bearer($admin), 'page');
        self::assertSame(200, $page['status']);
        self::assertCount(1, $page['body']['data']);
        self::assertGreaterThanOrEqual(3, $page['body']['meta']['total']);
        $found = $this->api->handle('GET', '/api/v1/vendors', ['q' => 'Page Goods A'], [], [], 'search');
        self::assertSame(200, $found['status'], json_encode($found['body']));
        self::assertSame('Page Goods A', $found['body']['data'][0]['name']);
        self::assertArrayNotHasKey('email', $found['body']['data'][0]);
        $offers = $this->api->handle('GET', '/api/v1/offers', [
            'vendor_ids' => $first['vendor_id'] . ',' . $second['vendor_id'],
        ], [], $this->bearer($admin), 'offers');
        self::assertSame(200, $offers['status'], json_encode($offers['body']));
        foreach ($offers['body']['data'] as $offer) {
            self::assertContains($offer['vendor_id'], [$first['vendor_id'], $second['vendor_id']]);
        }
    }

    /**
     * @param array{token: string, role: string, subject_id: int} $admin
     * @return array{token: string, role: string, subject_id: int, vendor_id: int}
     */
    private function vendor(array $admin, string $email, string $code, int $stock = 5): array
    {
        $this->api->handle('POST', '/api/v1/auth/register/vendor', [], [
            'name' => 'Page Goods ' . $code,
            'email' => $email,
            'password' => 'vendor-password-1',
            'code' => $code,
        ], [], 'vendor-' . $code);
        $vendors = $this->api->handle('GET', '/api/v1/admin/vendors', ['per_page' => 100], [], $this->bearer($admin), 'vendors-' . $code);
        $id = 0;
        foreach ($vendors['body']['data'] as $row) {
            if ($row['code'] === $code) {
                $id = (int) $row['id'];
            }
        }
        self::assertGreaterThan(0, $id);
        $this->api->handle('POST', '/api/v1/admin/vendors/' . $id . '/approve', [], [], $this->bearer($admin), 'approve-' . $code);
        $token = $this->login('vendor', $email, 'vendor-password-1');
        $this->api->handle('POST', '/api/v1/vendors/me/shipping-rules', [], [
            'name' => 'Free',
            'base_cents' => 0,
            'free_threshold_cents' => null,
            'surcharge_cents_per_kg' => 0,
            'postcode_prefixes' => [],
        ], $this->bearer($token), 'ship-' . $code);
        $this->api->handle('POST', '/api/v1/vendors/me/offers', [], [
            'product_id' => 50,
            'sku' => 'SKU-' . $code,
            'title' => 'Sample',
            'price_cents' => 10000,
            'discount' => '0',
            'stock' => $stock,
            'weight_grams' => 0,
        ], $this->bearer($token), 'offer-' . $code);
        $this->api->handle('POST', '/api/v1/vendors/me/mangopay/identity', [], [
            'legal_type' => 'natural',
            'first_name' => 'Lena',
            'last_name' => 'Vogel',
            'country' => 'DE',
            'nationality' => 'DE',
            'birthday' => '1990-01-02',
        ], $this->bearer($token), 'kyc-' . $code);
        $token['vendor_id'] = $id;

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
     * @param array{token: string} $vendor
     * @return array<string, mixed>
     */
    private function checkout(array $shopper, array $vendor, string $key): array
    {
        $response = $this->api->handle('POST', '/api/v1/checkout', [], [
            'postcode' => '10115',
            'lines' => [['product_id' => 50, 'quantity' => 1]],
        ], $this->bearer($shopper) + ['Idempotency-Key' => 'checkout-' . $key], 'checkout-' . $key);
        self::assertSame(201, $response['status'], json_encode($response['body']));

        return $response['body']['data'];
    }

    /**
     * @param array{token: string, subject_id: int} $shopper
     * @return array{status: int, body: array<string, mixed>}
     */
    private function pay(array $shopper, int $orderId, string $card, string $key): array
    {
        return $this->api->handle('POST', '/api/v1/payments', [], [
            'order_id' => $orderId,
            'card_id' => $card,
            'return_url' => 'https://shop.example/return',
        ], $this->bearer($shopper) + ['Idempotency-Key' => $key], $key);
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
        ], [], $email . $role);
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

final class RecordingOrders implements NativeOrderPort
{
    /** @var list<array{0: string, 1: int}> */
    public array $calls = [];

    public function __construct(private readonly string $code)
    {
    }

    public function createForPaidOrder(string $reference, int $customerId): array
    {
        $this->calls[] = [$reference, $customerId];

        return [['vendor_reference' => $reference . '-' . $this->code, 'id_order' => 900]];
    }
}
