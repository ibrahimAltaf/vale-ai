<?php

declare(strict_types=1);

namespace Marketplace\Tests\Platform;

use Marketplace\Commission\Service\CommissionCalculator;
use Marketplace\Platform\Api\ApiServer;
use Marketplace\Platform\Mangopay\MemoryGateway;
use Marketplace\Platform\Platform;
use Marketplace\Platform\PlatformException;
use PHPUnit\Framework\TestCase;

final class MarketplaceFlowTest extends TestCase
{
    private string $directory;

    private Platform $platform;

    private MemoryGateway $gateway;

    private ApiServer $api;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required. Run php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit.');
        }
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp-flow-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->gateway = new MemoryGateway();
        $this->platform = Platform::open(
            $this->directory . DIRECTORY_SEPARATOR . 'shop.sqlite',
            $this->gateway,
            'admin@example.com',
            password_hash('admin-test-password', PASSWORD_DEFAULT),
            $this->directory . DIRECTORY_SEPARATOR . 'invoices',
        );
        $this->api = new ApiServer($this->platform);
    }

    protected function tearDown(): void
    {
        unset($this->api, $this->platform, $this->gateway);
        gc_collect_cycles();
        $this->deleteTree($this->directory);
    }

    public function testPendingVendorCannotLoginUntilApproved(): void
    {
        $this->api->handle('POST', '/api/v1/auth/register/vendor', [], [
            'name' => 'Pending Goods',
            'email' => 'pending@example.com',
            'password' => 'pending-password-1',
            'code' => 'P',
        ], [], 'client-a');
        $denied = $this->api->handle('POST', '/api/v1/auth/login', [], [
            'role' => 'vendor',
            'email' => 'pending@example.com',
            'password' => 'pending-password-1',
        ], [], 'client-a');
        self::assertSame(403, $denied['status']);
        self::assertSame('vendor_not_active', $denied['body']['error']['code']);

        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $vendors = $this->api->handle('GET', '/api/v1/admin/vendors', [], [], $this->bearer($admin), 'admin');
        $id = (int) $vendors['body']['data'][0]['id'];
        $approved = $this->api->handle('POST', '/api/v1/admin/vendors/' . $id . '/approve', [], [], $this->bearer($admin), 'admin');
        self::assertSame('active', $approved['body']['data']['status']);
        $allowed = $this->api->handle('POST', '/api/v1/auth/login', [], [
            'role' => 'vendor',
            'email' => 'pending@example.com',
            'password' => 'pending-password-1',
        ], [], 'client-a');
        self::assertSame(200, $allowed['status']);
    }

    public function testBreakfastAllocationMatchesTheVerifiedTotal(): void
    {
        $this->seedBreakfast();
        $shopper = $this->registerShopper();
        $result = $this->api->handle('POST', '/api/v1/cart/optimize', [], [
            'postcode' => '10115',
            'lines' => [
                ['product_id' => 2, 'quantity' => 1],
                ['product_id' => 3, 'quantity' => 1],
            ],
        ], $this->bearer($shopper), 'shopper');
        self::assertSame(200, $result['status']);
        self::assertSame('OPTIMAL_VERIFIED', $result['body']['data']['status']);
        self::assertTrue($result['body']['data']['optimal']);
        self::assertSame(5200, $result['body']['data']['product_cents']);
        self::assertSame(0, $result['body']['data']['shipping_cents']);
        self::assertSame(5200, $result['body']['data']['grand_cents']);
        self::assertSame(['A'], array_column($result['body']['data']['vendors'], 'vendor_code'));
    }

    public function testPostcodeExcludesNorthwindAndMunichUsesWeight(): void
    {
        $this->seedBreakfast();
        $shopper = $this->registerShopper();
        $excluded = $this->api->handle('POST', '/api/v1/shipping/quote', [], [
            'vendor_id' => 3,
            'postcode' => '80331',
            'product_cents' => 1000,
            'weight_grams' => 1000,
        ], $this->bearer($shopper), 'shopper');
        self::assertFalse($excluded['body']['data']['eligible']);

        $munich = $this->api->handle('POST', '/api/v1/shipping/quote', [], [
            'vendor_id' => 2,
            'postcode' => '80331',
            'product_cents' => 1000,
            'weight_grams' => 1000,
        ], $this->bearer($shopper), 'shopper');
        self::assertTrue($munich['body']['data']['eligible']);
        self::assertSame(450, $munich['body']['data']['amount_cents']);
        self::assertSame('Munich weight delivery', $munich['body']['data']['rule_name']);
    }

    public function testFallbackDoesNotClaimOptimality(): void
    {
        $directory = $this->directory . '-fallback';
        mkdir($directory);
        $platform = Platform::open(
            $directory . '/shop.sqlite',
            new MemoryGateway(),
            'admin@example.com',
            password_hash('admin-test-password', PASSWORD_DEFAULT),
            $directory . '/invoices',
            120,
            0,
        );
        $api = new ApiServer($platform);
        $admin = $this->loginOn($api, 'admin', 'admin@example.com', 'admin-test-password');
        $vendor = $this->registerAndApproveOn($api, $admin, 'Fallback Goods', 'fallback@example.com', 'F');
        $token = $this->loginOn($api, 'vendor', 'fallback@example.com', 'vendor-password-1');
        $api->handle('POST', '/api/v1/vendors/me/shipping-rules', [], [
            'name' => 'Nationwide',
            'base_cents' => 0,
            'free_threshold_cents' => null,
            'surcharge_cents_per_kg' => 0,
            'postcode_prefixes' => [],
        ], $this->bearer($token), 'vendor-f');
        $api->handle('POST', '/api/v1/vendors/me/offers', [], $this->offer(2, 'MUESLI-1KG', 3600, 8, 1000, 'Muesli'), $this->bearer($token), 'vendor-f');
        $shopper = $this->registerShopperOn($api);
        $result = $api->handle('POST', '/api/v1/cart/optimize', [], [
            'postcode' => '10115',
            'lines' => [['product_id' => 2, 'quantity' => 1]],
        ], $this->bearer($shopper), 'shopper-f');
        self::assertSame('FALLBACK_NOT_PROVEN_OPTIMAL', $result['body']['data']['status']);
        self::assertFalse($result['body']['data']['optimal']);
        self::assertStringContainsString('not a proven optimum', $result['body']['data']['explanation']);
        unset($api, $platform, $vendor);
        gc_collect_cycles();
        $this->deleteTree($directory);
    }

    public function testFailedPaymentDoesNotPayTheOrderOrWriteCommission(): void
    {
        $ids = $this->seedPaidCatalog();
        $shopper = $this->registerShopper();
        $order = $this->checkout($shopper, $ids['product_id']);
        $failed = $this->api->handle('POST', '/api/v1/payments', [], [
            'order_id' => $order['id'],
            'card_id' => 'card_fail',
            'return_url' => 'https://shop.example/return',
        ], $this->bearer($shopper) + ['Idempotency-Key' => 'pay-fail-001'], 'shopper');
        self::assertSame(402, $failed['status']);
        self::assertSame('payment_failed', $failed['body']['error']['code']);
        $loaded = $this->api->handle('GET', '/api/v1/orders/' . $order['id'], [], [], $this->bearer($shopper), 'shopper');
        self::assertSame('failed', $loaded['body']['data']['payment_status']);
        self::assertNotSame('paid', $loaded['body']['data']['status']);
        $ledger = $this->api->handle('GET', '/api/v1/vendors/me/commissions', [], [], $this->bearer($ids['vendor_token']), 'vendor');
        self::assertSame([], $ledger['body']['data']['entries']);
    }

    public function testPaymentRefundPayoutAndCommissionRounding(): void
    {
        $ids = $this->seedPaidCatalog();
        $shopper = $this->registerShopper();
        $order = $this->checkout($shopper, $ids['product_id']);
        $paid = $this->api->handle('POST', '/api/v1/payments', [], [
            'order_id' => $order['id'],
            'card_id' => 'card_ok',
            'return_url' => 'https://shop.example/return',
        ], $this->bearer($shopper) + ['Idempotency-Key' => 'pay-ok-001'], 'shopper');
        self::assertSame(201, $paid['status']);
        self::assertSame('succeeded', $paid['body']['data']['status']);
        $calculator = new CommissionCalculator();
        $sale = $calculator->calculate(10000, '10.00');
        $ledger = $this->api->handle('GET', '/api/v1/vendors/me/commissions', [], [], $this->bearer($ids['vendor_token']), 'vendor');
        self::assertSame($sale->commissionCents, $ledger['body']['data']['entries'][0]['commission_cents']);
        self::assertSame($sale->netCents, $ledger['body']['data']['entries'][0]['net_cents']);
        self::assertSame(9000, $ledger['body']['data']['available_cents']);

        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $refund = $this->api->handle('POST', '/api/v1/admin/refunds', [], [
            'order_id' => $order['id'],
            'amount_cents' => 2500,
        ], $this->bearer($admin) + ['Idempotency-Key' => 'refund-2500'], 'admin');
        self::assertSame(201, $refund['status']);
        self::assertSame('partially_refunded', $refund['body']['data']['payment_status']);
        $reversal = $calculator->calculate(2500, '10.00');
        $ledger = $this->api->handle('GET', '/api/v1/vendors/me/commissions', [], [], $this->bearer($ids['vendor_token']), 'vendor');
        $fees = array_sum(array_column($ledger['body']['data']['entries'], 'commission_cents'));
        self::assertSame($sale->commissionCents - $reversal->commissionCents, $fees);
        self::assertSame(6750, $ledger['body']['data']['available_cents']);

        $kyc = $this->api->handle('POST', '/api/v1/vendors/me/mangopay/kyc', [], [], $this->bearer($ids['vendor_token']), 'vendor');
        self::assertSame('VALIDATED', $kyc['body']['data']['kyc_status']);
        $payout = $this->api->handle('POST', '/api/v1/vendors/me/payouts', [], [
            'amount_cents' => 6750,
        ], $this->bearer($ids['vendor_token']) + ['Idempotency-Key' => 'payout-6750'], 'vendor');
        self::assertSame(201, $payout['status']);
        $processed = $this->api->handle('POST', '/api/v1/admin/payouts/' . $payout['body']['data']['id'] . '/process', [], [], $this->bearer($admin), 'admin');
        self::assertSame('processed', $processed['body']['data']['status']);
        $ledger = $this->api->handle('GET', '/api/v1/vendors/me/commissions', [], [], $this->bearer($ids['vendor_token']), 'vendor');
        self::assertSame(0, $ledger['body']['data']['available_cents']);
        $books = $this->api->handle('GET', '/api/v1/admin/reconciliation', [], [], $this->bearer($admin), 'admin');
        $kinds = array_column($books['body']['data']['entries'], 'kind');
        self::assertContains('payment', $kinds);
        self::assertContains('refund', $kinds);
        self::assertContains('transfer', $kinds);
    }

    public function testWebhookIsVerifiedAndCannotDuplicateAPayment(): void
    {
        $ids = $this->seedPaidCatalog();
        $shopper = $this->registerShopper();
        $order = $this->checkout($shopper, $ids['product_id']);
        $pending = $this->api->handle('POST', '/api/v1/payments', [], [
            'order_id' => $order['id'],
            'card_id' => 'card_pending',
            'return_url' => 'https://shop.example/return',
        ], $this->bearer($shopper) + ['Idempotency-Key' => 'pay-pending-1'], 'shopper');
        self::assertSame(202, $pending['status']);
        self::assertSame('pending_provider', $pending['body']['data']['status']);
        $providerId = $pending['body']['data']['provider_id'];
        $unknown = $this->api->handle('POST', '/api/v1/mangopay/webhooks', [], [
            'EventType' => 'PAYIN_NORMAL_SUCCEEDED',
            'RessourceId' => 'payin_missing',
        ], [], 'mangopay');
        self::assertSame(401, $unknown['status']);

        $this->gateway->update($providerId, ['Status' => 'SUCCEEDED']);
        $first = $this->api->handle('POST', '/api/v1/mangopay/webhooks', [], [
            'EventType' => 'PAYIN_NORMAL_SUCCEEDED',
            'RessourceId' => $providerId,
        ], [], 'mangopay');
        $second = $this->api->handle('POST', '/api/v1/mangopay/webhooks', [], [
            'EventType' => 'PAYIN_NORMAL_SUCCEEDED',
            'RessourceId' => $providerId,
        ], [], 'mangopay');
        self::assertFalse($first['body']['data']['duplicate']);
        self::assertTrue($second['body']['data']['duplicate']);
        $loaded = $this->api->handle('GET', '/api/v1/orders/' . $order['id'], [], [], $this->bearer($shopper), 'shopper');
        self::assertSame('paid', $loaded['body']['data']['payment_status']);
        $ledger = $this->api->handle('GET', '/api/v1/vendors/me/commissions', [], [], $this->bearer($ids['vendor_token']), 'vendor');
        self::assertCount(1, $ledger['body']['data']['entries']);
    }

    public function testSameIdempotencyKeyDoesNotCreateTwoOrders(): void
    {
        $ids = $this->seedPaidCatalog();
        $shopper = $this->registerShopper();
        $body = ['postcode' => '10115', 'lines' => [['product_id' => $ids['product_id'], 'quantity' => 1]]];
        $headers = $this->bearer($shopper) + ['Idempotency-Key' => 'checkout-same-1'];
        $first = $this->api->handle('POST', '/api/v1/checkout', [], $body, $headers, 'shopper');
        $second = $this->api->handle('POST', '/api/v1/checkout', [], $body, $headers, 'shopper');
        self::assertSame($first['body']['data']['id'], $second['body']['data']['id']);
        $changed = $body;
        $changed['lines'][0]['quantity'] = 2;
        $conflict = $this->api->handle('POST', '/api/v1/checkout', [], $changed, $headers, 'shopper');
        self::assertSame(409, $conflict['status']);
        self::assertSame('idempotency_conflict', $conflict['body']['error']['code']);
    }

    public function testVendorCannotReadAnotherVendorsOrderAndShopperCannotEither(): void
    {
        $ids = $this->seedPaidCatalog();
        $other = $this->registerAndApprove('Other Goods', 'other@example.com', 'O');
        $otherToken = $this->login('vendor', 'other@example.com', 'vendor-password-1');
        $shopper = $this->registerShopper();
        $order = $this->checkout($shopper, $ids['product_id']);
        $vendorOrderId = (int) $order['vendor_orders'][0]['id'];
        $forbidden = $this->api->handle('POST', '/api/v1/vendors/me/orders/' . $vendorOrderId . '/fulfill', [], [], $this->bearer($otherToken), 'other');
        self::assertSame(403, $forbidden['status']);
        $ownList = $this->api->handle('GET', '/api/v1/vendors/me/orders', [], [], $this->bearer($otherToken), 'other');
        self::assertSame([], $ownList['body']['data']);
        $intruder = $this->registerShopper('intruder@example.com');
        $hidden = $this->api->handle('GET', '/api/v1/orders/' . $order['id'], [], [], $this->bearer($intruder), 'intruder');
        self::assertSame(404, $hidden['status']);
        unset($other);
    }

    public function testRawCardDataAndInjectedPostcodeAreRejected(): void
    {
        $shopper = $this->registerShopper();
        $card = $this->api->handle('POST', '/api/v1/payments', [], [
            'order_id' => 1,
            'card_id' => 'card_ok',
            'pan' => '4242424242424242',
            'return_url' => 'https://shop.example/return',
        ], $this->bearer($shopper) + ['Idempotency-Key' => 'card-reject-1'], 'shopper');
        self::assertSame(422, $card['status']);
        self::assertSame('card_data_rejected', $card['body']['error']['code']);
        $postcode = $this->api->handle('POST', '/api/v1/cart/optimize', [], [
            'postcode' => "10115' OR '1'='1",
            'lines' => [['product_id' => 1, 'quantity' => 1]],
        ], $this->bearer($shopper), 'shopper');
        self::assertSame(422, $postcode['status']);
    }

    public function testCsvImportIsSafeToRetryAndCronRunsDueSchedules(): void
    {
        $vendor = $this->registerAndApprove('Import Goods', 'import@example.com', 'I');
        $token = $this->login('vendor', 'import@example.com', 'vendor-password-1');
        $csv = "vendor,sku,ean,brand,title,description,price,discount,stock,weight,image_url\n"
            . "I,SOAP-1,4000000000099,Clean,Soap,Bar,3.40,0,10,0.5,https://cdn.example/soap.jpg\n";
        $first = $this->api->handle('POST', '/api/v1/vendors/me/imports', [], [
            'filename' => 'soap.csv',
            'csv' => $csv,
        ], $this->bearer($token), 'import');
        self::assertSame(1, $first['body']['data']['rows_created']);
        $second = $this->api->handle('POST', '/api/v1/vendors/me/imports', [], [
            'filename' => 'soap.csv',
            'csv' => $csv,
        ], $this->bearer($token), 'import');
        self::assertSame(0, $second['body']['data']['rows_created']);
        self::assertSame(1, $second['body']['data']['rows_updated']);
        $offers = $this->api->handle('GET', '/api/v1/inventory', [], [], $this->bearer($token), 'import');
        self::assertCount(1, $offers['body']['data']);

        $this->api->handle('POST', '/api/v1/vendors/me/import-schedules', [], [
            'filename' => 'soap.csv',
            'csv' => $csv,
            'interval_minutes' => 5,
        ], $this->bearer($token), 'import');
        $run = $this->platform->runMaintenance(time());
        self::assertCount(1, $run['imports']);
        self::assertSame(1, $run['imports'][0]['rows_updated']);
        $again = $this->platform->runMaintenance(time());
        self::assertSame([], $again['imports']);
        unset($vendor);
    }

    public function testInvoiceUploadIsPrivateAndCustomerCanDownloadIt(): void
    {
        $ids = $this->seedPaidCatalog();
        $shopper = $this->registerShopper();
        $order = $this->checkout($shopper, $ids['product_id']);
        $this->api->handle('POST', '/api/v1/payments', [], [
            'order_id' => $order['id'],
            'card_id' => 'card_ok',
            'return_url' => 'https://shop.example/return',
        ], $this->bearer($shopper) + ['Idempotency-Key' => 'pay-invoice-1'], 'shopper');
        $vendorOrderId = (int) $order['vendor_orders'][0]['id'];
        $created = $this->api->handle('POST', '/api/v1/vendors/me/invoices', [], [
            'vendor_order_id' => $vendorOrderId,
        ], $this->bearer($ids['vendor_token']), 'vendor');
        self::assertSame('pending', $created['body']['data']['status']);
        $issued = $this->api->handle('POST', '/api/v1/invoices/' . $created['body']['data']['id'] . '/issue', [], [], $this->bearer($ids['vendor_token']), 'vendor');
        self::assertSame('issued', $issued['body']['data']['status']);
        $uploaded = $this->api->handle('POST', '/api/v1/invoices/' . $created['body']['data']['id'] . '/upload', [], [
            'content_base64' => base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"),
        ], $this->bearer($ids['vendor_token']), 'vendor');
        self::assertSame('uploaded', $uploaded['body']['data']['status']);
        $download = $this->api->handle('GET', '/api/v1/invoices/' . $created['body']['data']['id'] . '/download', [], [], $this->bearer($shopper), 'shopper');
        self::assertSame(200, $download['status']);
        self::assertStringStartsWith('%PDF', base64_decode((string) $download['body']['data']['content_base64'], true) ?: '');
        $intruder = $this->registerShopper('invoice-intruder@example.com');
        $hidden = $this->api->handle('GET', '/api/v1/invoices/' . $created['body']['data']['id'] . '/download', [], [], $this->bearer($intruder), 'intruder');
        self::assertSame(404, $hidden['status']);
        $other = $this->registerAndApprove('Invoice Other', 'invoice-other@example.com', 'N');
        $otherToken = $this->login('vendor', 'invoice-other@example.com', 'vendor-password-1');
        $hiddenVendor = $this->api->handle('GET', '/api/v1/invoices/' . $created['body']['data']['id'] . '/download', [], [], $this->bearer($otherToken), 'other-vendor');
        self::assertSame(404, $hiddenVendor['status']);
        unset($other);
        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $commission = $this->api->handle('POST', '/api/v1/admin/invoices/commission', [], [
            'vendor_order_id' => $vendorOrderId,
        ], $this->bearer($admin), 'admin');
        self::assertSame('commission', $commission['body']['data']['kind']);
        self::assertSame(1000, $commission['body']['data']['total_cents']);
    }

    public function testSecondCheckoutCannotOversellTheLastUnit(): void
    {
        $ids = $this->seedPaidCatalog(1);
        $first = $this->registerShopper('first@example.com');
        $second = $this->registerShopper('second@example.com');
        $ok = $this->checkout($first, $ids['product_id']);
        self::assertSame('reserved', $ok['status']);
        $denied = $this->api->handle('POST', '/api/v1/checkout', [], [
            'postcode' => '10115',
            'lines' => [['product_id' => $ids['product_id'], 'quantity' => 1]],
        ], $this->bearer($second) + ['Idempotency-Key' => 'checkout-second'], 'second');
        self::assertContains($denied['status'], [409]);
        self::assertContains($denied['body']['error']['code'], ['infeasible', 'insufficient_stock']);
    }

    public function testRateLimitAndBadSortAreRejected(): void
    {
        $directory = $this->directory . '-rate';
        mkdir($directory);
        $platform = Platform::open(
            $directory . '/shop.sqlite',
            new MemoryGateway(),
            'admin@example.com',
            password_hash('admin-test-password', PASSWORD_DEFAULT),
            $directory . '/invoices',
            2,
        );
        $api = new ApiServer($platform);
        $body = ['role' => 'admin', 'email' => 'admin@example.com', 'password' => 'wrong-password-1'];
        $api->handle('POST', '/api/v1/auth/login', [], $body, [], 'same-client');
        $api->handle('POST', '/api/v1/auth/login', [], $body, [], 'same-client');
        $limited = $api->handle('POST', '/api/v1/auth/login', [], $body, [], 'same-client');
        self::assertSame(429, $limited['status']);
        $shopper = $this->registerShopperOn($api, 'rate@example.com');
        $sorted = $api->handle('GET', '/api/v1/offers', ['sort' => 'price_cents;drop'], [], $this->bearer($shopper), 'rate-client');
        self::assertSame(422, $sorted['status']);
        unset($api, $platform);
        gc_collect_cycles();
        $this->deleteTree($directory);
    }

    /**
     * @return array{token: string, role: string, subject_id: int}
     */
    private function login(string $role, string $email, string $password): array
    {
        return $this->loginOn($this->api, $role, $email, $password);
    }

    /**
     * @return array{token: string, role: string, subject_id: int}
     */
    private function loginOn(ApiServer $api, string $role, string $email, string $password): array
    {
        $response = $api->handle('POST', '/api/v1/auth/login', [], [
            'role' => $role,
            'email' => $email,
            'password' => $password,
        ], [], $email);
        self::assertSame(200, $response['status'], json_encode($response['body']));

        return $response['body']['data'];
    }

    /**
     * @return array{token: string, role: string, subject_id: int}
     */
    private function registerShopper(string $email = 'nora@example.com'): array
    {
        return $this->registerShopperOn($this->api, $email);
    }

    /**
     * @return array{token: string, role: string, subject_id: int}
     */
    private function registerShopperOn(ApiServer $api, string $email = 'nora@example.com'): array
    {
        $created = $api->handle('POST', '/api/v1/auth/register/customer', [], [
            'email' => $email,
            'password' => 'shopper-password-1',
            'first_name' => 'Nora',
            'last_name' => 'Keller',
        ], [], $email);
        self::assertSame(201, $created['status'], json_encode($created['body']));

        return $this->loginOn($api, 'shopper', $email, 'shopper-password-1');
    }

    /**
     * @return array<string, mixed>
     */
    private function registerAndApprove(string $name, string $email, string $code): array
    {
        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');

        return $this->registerAndApproveOn($this->api, $admin, $name, $email, $code);
    }

    /**
     * @param array{token: string, role: string, subject_id: int} $admin
     * @return array<string, mixed>
     */
    private function registerAndApproveOn(ApiServer $api, array $admin, string $name, string $email, string $code): array
    {
        $created = $api->handle('POST', '/api/v1/auth/register/vendor', [], [
            'name' => $name,
            'email' => $email,
            'password' => 'vendor-password-1',
            'code' => $code,
        ], [], $email);
        self::assertSame(201, $created['status'], json_encode($created['body']));
        $id = (int) $created['body']['data']['id'];
        $api->handle('POST', '/api/v1/admin/vendors/' . $id . '/approve', [], [], $this->bearer($admin), 'admin-' . $code);
        $api->handle('POST', '/api/v1/admin/vendors/' . $id . '/commission', [], [
            'commission_percent' => '10.00',
        ], $this->bearer($admin), 'admin-fee-' . $code);

        return $created['body']['data'];
    }

    private function seedBreakfast(): void
    {
        $admin = $this->login('admin', 'admin@example.com', 'admin-test-password');
        $vendors = [
            ['A', 'Alpine Roast', 'alpine@example.com', '10.00', 500, 5000, 0, []],
            ['B', 'Harbor Bean', 'harbor@example.com', '12.00', 300, 8000, 0, []],
            ['C', 'Northwind Pantry', 'north@example.com', '8.00', 700, 4000, 0, ['10', '20', '75']],
        ];
        $tokens = [];
        foreach ($vendors as [$code, $name, $email, $percent, $base, $free, $surcharge, $prefixes]) {
            $created = $this->registerAndApproveOn($this->api, $admin, $name, $email, $code);
            $this->api->handle('POST', '/api/v1/admin/vendors/' . $created['id'] . '/commission', [], [
                'commission_percent' => $percent,
            ], $this->bearer($admin), 'fee-' . $code);
            $token = $this->login('vendor', $email, 'vendor-password-1');
            $tokens[$code] = $token;
            $this->api->handle('POST', '/api/v1/vendors/me/shipping-rules', [], [
                'name' => 'Primary',
                'base_cents' => $base,
                'free_threshold_cents' => $free,
                'surcharge_cents_per_kg' => $surcharge,
                'postcode_prefixes' => $prefixes,
            ], $this->bearer($token), 'ship-' . $code);
        }
        $this->api->handle('POST', '/api/v1/vendors/me/shipping-rules', [], [
            'name' => 'Munich weight delivery',
            'base_cents' => 300,
            'free_threshold_cents' => 8000,
            'surcharge_cents_per_kg' => 150,
            'postcode_prefixes' => ['80'],
        ], $this->bearer($tokens['B']), 'ship-b-extra');
        $offers = [
            'A' => [[2, 'MUESLI-1KG', 3600, 8, 1000, 'Alpine Muesli 1kg'], [3, 'HONEY-500', 1600, 8, 500, 'Wildflower Honey 500g']],
            'B' => [[2, 'MUESLI-1KG', 3000, 8, 1000, 'Alpine Muesli 1kg'], [3, 'HONEY-500', 2200, 8, 500, 'Wildflower Honey 500g']],
            'C' => [[2, 'MUESLI-1KG', 4100, 8, 1000, 'Alpine Muesli 1kg'], [3, 'HONEY-500', 1500, 8, 500, 'Wildflower Honey 500g']],
        ];
        foreach ($offers as $code => $rows) {
            foreach ($rows as $row) {
                $saved = $this->api->handle('POST', '/api/v1/vendors/me/offers', [], $this->offer(...$row), $this->bearer($tokens[$code]), 'offer-' . $code . $row[0]);
                self::assertSame(201, $saved['status'], json_encode($saved['body']));
            }
        }
    }

    /**
     * @return array{product_id: int, vendor_token: array{token: string, role: string, subject_id: int}}
     */
    private function seedPaidCatalog(int $stock = 5): array
    {
        $vendor = $this->registerAndApprove('Ledger Goods', 'ledger@example.com', 'L');
        $token = $this->login('vendor', 'ledger@example.com', 'vendor-password-1');
        $this->api->handle('POST', '/api/v1/vendors/me/shipping-rules', [], [
            'name' => 'Free',
            'base_cents' => 0,
            'free_threshold_cents' => null,
            'surcharge_cents_per_kg' => 0,
            'postcode_prefixes' => [],
        ], $this->bearer($token), 'ledger-ship');
        $offer = $this->api->handle('POST', '/api/v1/vendors/me/offers', [], $this->offer(50, 'LEDGER-1', 10000, $stock, 0, 'Ledger item'), $this->bearer($token), 'ledger-offer');
        self::assertSame(201, $offer['status'], json_encode($offer['body']));
        $this->api->handle('POST', '/api/v1/vendors/me/mangopay/identity', [], [
            'legal_type' => 'natural',
            'first_name' => 'Lena',
            'last_name' => 'Vogel',
            'country' => 'DE',
            'nationality' => 'DE',
            'birthday' => '1990-01-02',
        ], $this->bearer($token), 'ledger-kyc');
        unset($vendor);

        return ['product_id' => 50, 'vendor_token' => $token];
    }

    /**
     * @param array{token: string, role: string, subject_id: int} $shopper
     * @return array<string, mixed>
     */
    private function checkout(array $shopper, int $productId): array
    {
        $response = $this->api->handle('POST', '/api/v1/checkout', [], [
            'postcode' => '10115',
            'lines' => [['product_id' => $productId, 'quantity' => 1]],
        ], $this->bearer($shopper) + ['Idempotency-Key' => 'checkout-' . $shopper['subject_id'] . '-' . $productId], 'checkout-' . $shopper['subject_id']);
        self::assertSame(201, $response['status'], json_encode($response['body']));

        return $response['body']['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(int $productId, string $sku, int $price, int $stock, int $weight, string $title): array
    {
        return [
            'product_id' => $productId,
            'sku' => $sku,
            'ean' => '',
            'title' => $title,
            'price_cents' => $price,
            'discount' => '0',
            'stock' => $stock,
            'weight_grams' => $weight,
        ];
    }

    /**
     * @param array{token: string} $session
     * @return array<string, string>
     */
    private function bearer(array $session): array
    {
        return ['Authorization' => 'Bearer ' . $session['token']];
    }

    private function deleteTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteTree($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
