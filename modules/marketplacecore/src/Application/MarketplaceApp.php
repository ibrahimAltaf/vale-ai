<?php

declare(strict_types=1);

namespace Marketplace\Core\Application;

use Marketplace\Catalog\Dto\CatalogProduct;
use Marketplace\Catalog\Dto\ImportAction;
use Marketplace\Catalog\Service\CsvOfferParser;
use Marketplace\Catalog\Service\OfferComparison;
use Marketplace\Catalog\Service\OfferImporter;
use Marketplace\Catalog\Service\ProductMatcher;
use Marketplace\Collections\Dto\CollectionWindow;
use Marketplace\Collections\Service\CollectionSchedule;
use Marketplace\Commission\Service\CommissionCalculator;
use Marketplace\Core\Database\Connection;
use Marketplace\Core\Domain\Money;
use Marketplace\Core\Domain\OptimizationStatus;
use Marketplace\Core\Install\PrestaShopProductGateway;
use Marketplace\Core\Install\SchemaInstaller;
use Marketplace\Core\Repository\CartRepository;
use Marketplace\Core\Repository\CatalogRepository;
use Marketplace\Core\Repository\CollectionRepository;
use Marketplace\Core\Repository\DashboardRepository;
use Marketplace\Core\Repository\OrderRepository;
use Marketplace\Core\Repository\VendorRepository;
use Marketplace\Core\Seed\DemoCatalog;
use Marketplace\Optimizer\Dto\CartInput;
use Marketplace\Optimizer\Dto\CartLine;
use Marketplace\Optimizer\Dto\OptimizationResult;
use Marketplace\Optimizer\Service\CartOptimizer;
use Marketplace\Orders\Adapter\PrestaShopStockAccount;
use Marketplace\Orders\Service\OrderSplitter;
use Marketplace\Orders\Service\StockReservationService;
use Marketplace\Shipping\Service\ShippingCalculator;

/**
 * Composition root used by storefront and back-office controllers.
 */
final class MarketplaceApp
{
    private Connection $db;

    public function __construct()
    {
        (new SchemaInstaller())->upgrade();
        $this->db = new Connection();
    }

    public static function instance(): self
    {
        return new self();
    }

    public function catalog(string $postcode): array
    {
        $products = [];
        $catalog = new CatalogRepository($this->db);
        foreach ($catalog->masterProducts() as $product) {
            $products[] = $this->productCard((int) $product['id_product'], (string) $product['name'], (string) $product['reference'], (string) $product['ean13'], $postcode);
        }

        return $products;
    }

    public function productCard(int $productId, string $name, string $sku, string $ean, string $postcode): array
    {
        if ($name === '' || $sku === '' || $ean === '') {
            $langId = (int) \Configuration::get('PS_LANG_DEFAULT');
            $identity = $this->db->all(
                'SELECT p.`reference`, p.`ean13`, pl.`name` FROM `' . _DB_PREFIX_ . 'product` p
                 INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product`
                 WHERE p.`id_product` = ' . (int) $productId . ' AND pl.`id_lang` = ' . $langId
            );
            if ($identity !== []) {
                $name = $name !== '' ? $name : (string) $identity[0]['name'];
                $sku = $sku !== '' ? $sku : (string) $identity[0]['reference'];
                $ean = $ean !== '' ? $ean : (string) $identity[0]['ean13'];
            }
        }
        $rows = (new CatalogRepository($this->db))->offerRows($productId, null, $postcode);
        $comparisonRows = [];
        foreach ($rows as $row) {
            if ((string) $row['vendor_status'] !== 'active') {
                continue;
            }
            $discount = number_format((float) $row['discount_percent'], 2, '.', '');
            $net = Money::afterDiscount((int) $row['price_cents'], $discount);
            $available = max(0, (int) $row['quantity'] - (int) $row['reserved_quantity']);
            $prefixes = trim((string) $row['postcode_prefixes']);
            $comparisonRows[] = [
                'offer_id' => (int) $row['id_marketplace_vendor_offer'],
                'vendor_id' => (int) $row['id_marketplace_vendor'],
                'vendor_code' => (string) $row['code'],
                'vendor_name' => (string) $row['vendor_name'],
                'list_cents' => (int) $row['price_cents'],
                'net_cents' => $net,
                'stock' => $available,
                'weight_grams' => (int) $row['weight_grams'],
                'discount' => $discount,
                'shipping' => new \Marketplace\Shipping\Dto\ShippingRule(
                    (int) $row['id_marketplace_vendor'],
                    (int) $row['base_cents'],
                    $row['free_threshold_cents'] === null ? null : (int) $row['free_threshold_cents'],
                    (int) $row['surcharge_cents_per_kg'],
                    $prefixes === '' ? [] : explode(',', $prefixes),
                ),
            ];
        }

        $compared = (new OfferComparison(new ShippingCalculator()))->compare($comparisonRows, $postcode);
        $offers = [];
        foreach ($compared->offers as $offer) {
            $source = null;
            foreach ($comparisonRows as $row) {
                if ($row['offer_id'] === $offer->offerId) {
                    $source = $row;
                }
            }
            $offers[] = [
                'vendor_code' => $offer->vendorCode,
                'vendor_name' => $offer->vendorName,
                'list' => Money::euros($offer->listCents),
                'discount' => $source['discount'] ?? '0.00',
                'net' => Money::euros($offer->netCents),
                'net_cents' => $offer->netCents,
                'stock' => $offer->stock,
                'eligible' => $offer->shippingEligible,
                'shipping' => $offer->shippingEligible ? Money::euros($offer->shippingCents) : 'Not eligible',
                'delivered' => $offer->deliveredCents === null ? '—' : Money::euros($offer->deliveredCents),
                'cheapest_unit' => $offer->cheapestUnit,
                'cheapest_delivered' => $offer->cheapestDelivered,
                'shipping_reason' => $offer->shippingReason,
                'low_stock' => $offer->stock <= 3,
            ];
        }

        $starting = null;
        $units = 0;
        foreach ($offers as $offer) {
            $units += (int) $offer['stock'];
            if ($offer['net_cents'] !== null && ($starting === null || $offer['net_cents'] < $starting)) {
                $starting = $offer['net_cents'];
            }
        }
        $brand = '';
        $description = '';
        foreach ($rows as $row) {
            if ($brand === '' && !empty($row['brand'])) {
                $brand = (string) $row['brand'];
            }
            if ($description === '' && !empty($row['description'])) {
                $description = (string) $row['description'];
            }
        }

        return [
            'id_product' => $productId,
            'name' => $name,
            'sku' => $sku,
            'ean' => $ean,
            'brand' => $brand,
            'description' => $this->shopperDescription($sku, $description),
            'category' => $this->defaultCategoryName($productId),
            'image' => $this->coverUrl($productId, $name, $sku),
            'offers' => $offers,
            'offer_count' => count($offers),
            'available_units' => $units,
            'starting' => $starting === null ? '—' : Money::euros($starting),
            'starting_cents' => $starting ?? 0,
            'compare_at' => $this->compareAt($offers, $starting),
            'url' => \Context::getContext()->link->getProductLink($productId),
        ];
    }

    public function collections(): array
    {
        $schedule = new CollectionSchedule();
        $now = new \DateTimeImmutable('now');
        $cards = [];
        foreach ((new CollectionRepository($this->db))->all() as $collection) {
            $window = new CollectionWindow(
                (string) $collection['name'],
                (string) $collection['slug'],
                (int) $collection['active'] === 1,
                $collection['date_start'] ? new \DateTimeImmutable((string) $collection['date_start']) : null,
                $collection['date_end'] ? new \DateTimeImmutable((string) $collection['date_end']) : null,
            );
            $cards[] = [
                'name' => $collection['name'],
                'slug' => $collection['slug'],
                'status' => $schedule->status($window, $now),
                'starts' => $collection['date_start'] ?: 'Open ended',
                'ends' => $collection['date_end'] ?: 'Open ended',
                'products' => $collection['products'],
            ];
        }

        return $cards;
    }

    /**
     * @param array<int, int> $quantities product id => quantity
     */
    public function cartView(array $lines, string $postcode): array
    {
        $view = [];
        foreach ($lines as $line) {
            $card = $this->productCard((int) $line['id_product'], (string) $line['name'], (string) $line['reference'], '', $postcode);
            $view[] = [
                'id_product' => $line['id_product'],
                'name' => $card['name'] !== '' ? $card['name'] : $line['name'],
                'reference' => $line['reference'],
                'sku' => $line['reference'],
                'quantity' => $line['quantity'],
                'image' => $card['image'],
                'brand' => $card['brand'],
                'from' => $card['starting'],
            ];
        }

        return ['lines' => $view, 'postcode' => $postcode, 'scenarios' => array_keys(DemoCatalog::scenarioQuantities())];
    }

    public function optimizeLines(array $lines, string $postcode): array
    {
        $input = $this->cartInput($lines, $postcode);
        $cache = \Marketplace\Core\Cache\RedisClient::fromEnvironment();
        $cacheKey = 'mp:opt:' . hash('sha256', $this->fingerprint($input));
        if ($cache instanceof \Marketplace\Core\Cache\RedisClient) {
            $cached = $cache->get($cacheKey);
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    $decoded['cached'] = true;

                    return $decoded;
                }
            }
        }

        $optimizer = new CartOptimizer(new ShippingCalculator());
        $result = $optimizer->optimize($input);
        $baseline = $result->status === OptimizationStatus::Infeasible ? null : $optimizer->unitPriceBaseline($input);
        $view = $this->presentOptimization($result, $baseline, $input);
        $view['cached'] = false;
        if ($cache instanceof \Marketplace\Core\Cache\RedisClient) {
            $cache->set($cacheKey, (string) json_encode($view), 30);
        }

        return $view;
    }

    /**
     * @param array{id_customer?: int, payment_method?: string, card_last4?: string, card_brand?: string, card_expiry?: string, card_holder?: string} $checkout
     * @return array{reference: string, email_sent: bool, shop_orders: list<array{id_order: int, reference: string}>}
     */
    public function confirmLines(array $lines, string $postcode, array $checkout = []): array
    {
        $input = $this->cartInput($lines, $postcode);
        $optimizer = new CartOptimizer(new ShippingCalculator());
        $result = $optimizer->optimize($input);
        if ($result->status === OptimizationStatus::Infeasible) {
            throw new \RuntimeException($result->explanation);
        }

        $plan = $this->db->transaction(function () use ($result, $input): array {
            $now = new \DateTimeImmutable('now');
            $quantities = [];
            foreach ($result->lines as $line) {
                $quantities[$line->offerId] = ($quantities[$line->offerId] ?? 0) + $line->quantity;
            }
            $reservationIds = (new StockReservationService())->reserve(
                new PrestaShopStockAccount($this->db),
                $quantities,
                $now->modify('+30 minutes'),
                $now,
            );
            $orders = new OrderRepository($this->db);
            $reference = $orders->nextReference();
            $split = (new OrderSplitter(new CommissionCalculator()))->split(
                $result,
                $input->postcode,
                $reference,
                (new VendorRepository($this->db))->commissionPercents(),
            );

            $orders->persist($split, $result->explanation, $reservationIds);

            return ['reference' => $reference];
        });

        $emailSent = false;
        $shopOrders = [];
        $shopError = '';
        if ($checkout !== []) {
            $customerId = (int) ($checkout['id_customer'] ?? 0);
            if ($customerId < 1) {
                throw new \RuntimeException('Sign in before paying.');
            }
            $method = (string) ($checkout['payment_method'] ?? '');
            if (!in_array($method, ['card', 'bankwire'], true)) {
                throw new \InvalidArgumentException('Choose card or bank transfer.');
            }
            $paid = $method === 'card';
            $label = $paid
                ? 'Card ···· ' . (string) ($checkout['card_last4'] ?? '')
                : 'Bank transfer';
            try {
                $orders = new OrderRepository($this->db);
                $orders->attachCheckout(
                    $plan['reference'],
                    $customerId,
                    $label,
                    $paid ? 'paid' : 'awaiting',
                    $paid ? 'paid' : 'awaiting_payment',
                );
                try {
                    $shopOrders = (new \Marketplace\Orders\Adapter\PrestaShopOrderGateway())->create($plan['reference'], $checkout);
                } catch (\Throwable $exception) {
                    $shopError = $exception->getMessage();
                }
                $emailSent = (new \Marketplace\Orders\Mail\OrderMailer())->sendOrder($plan['reference']);
            } catch (\Throwable $exception) {
                $shopError = $exception->getMessage();
            }
        }

        return [
            'reference' => $plan['reference'],
            'email_sent' => $emailSent,
            'shop_orders' => $shopOrders,
            'shop_error' => $shopError,
        ];
    }

    public function orderReceipt(string $reference): ?array
    {
        $order = (new OrderRepository($this->db))->findParent($reference);
        if ($order === null) {
            return null;
        }
        $vendors = [];
        foreach ($order['vendors'] as $vendor) {
            $lines = [];
            foreach ($vendor['lines'] as $line) {
                $lines[] = [
                    'name' => $line['product_name'],
                    'quantity' => (int) $line['quantity'],
                    'unit' => Money::euros((int) $line['unit_price_cents']),
                    'total' => Money::euros((int) $line['line_total_cents']),
                ];
            }
            $vendors[] = [
                'reference' => $vendor['reference'],
                'vendor' => $vendor['vendor_name'],
                'code' => $vendor['code'],
                'products' => Money::euros((int) $vendor['product_cents']),
                'shipping' => Money::euros((int) $vendor['shipping_cents']),
                'total' => Money::euros((int) $vendor['grand_cents']),
                'shop_reference' => (string) ($vendor['shop_reference'] ?? ''),
                'commission' => $vendor['commission'] ? Money::euros((int) $vendor['commission']['commission_cents']) : '—',
                'net' => $vendor['commission'] ? Money::euros((int) $vendor['commission']['net_cents']) : '—',
                'lines' => $lines,
            ];
        }

        return [
            'reference' => $order['reference'],
            'status' => $order['optimization_status'],
            'note' => $order['optimization_note'],
            'postcode' => $order['postcode'],
            'products' => Money::euros((int) $order['product_cents']),
            'shipping' => Money::euros((int) $order['shipping_cents']),
            'total' => Money::euros((int) $order['grand_cents']),
            'payment' => (string) ($order['payment_method'] ?? ''),
            'payment_status' => (string) ($order['payment_status'] ?? ''),
            'order_status' => (string) ($order['status'] ?? 'confirmed'),
            'vendors' => $vendors,
        ];
    }

    public function adminDashboard(): array
    {
        $summary = (new DashboardRepository($this->db))->adminSummary();

        return [
            'summary' => $this->moneySummary($summary),
            'vendors' => $this->vendorRows(),
            'orders' => $this->orderRows(null),
            'settlements' => $this->settlementRows(),
            'sales' => $this->salesSeries(null),
            'commissions' => $this->commissionSeries(),
            'imports' => $this->importJobs(),
            'performance' => $this->vendorPerformance(),
            'trends' => [
                'gmv' => $this->periodTrend('marketplace_order', 'COALESCE(SUM(`product_cents`), 0)', null),
                'orders' => $this->periodTrend('marketplace_order', 'COUNT(*)', null),
                'commission' => $this->periodTrend('marketplace_commission', 'COALESCE(SUM(`commission_cents`), 0)', null),
            ],
            'vendor_orders' => $this->ordersByVendor(),
        ];
    }

    public function vendorRows(): array
    {
        $rows = [];
        $rules = (new VendorRepository($this->db))->rulesGrouped();
        foreach ((new VendorRepository($this->db))->all() as $vendor) {
            $parts = [];
            foreach ($rules[(int) $vendor['id_marketplace_vendor']] ?? [] as $rule) {
                $parts[] = (string) $rule['name']
                    . ': base ' . Money::euros((int) $rule['base_cents'])
                    . ', free above ' . ($rule['free_threshold_cents'] === null ? 'never' : Money::euros((int) $rule['free_threshold_cents']))
                    . ', weight ' . Money::euros((int) $rule['surcharge_cents_per_kg']) . '/kg'
                    . ((string) $rule['postcode_prefixes'] === '' ? ', all postcodes' : ', postcodes ' . $rule['postcode_prefixes']);
            }
            $rows[] = [
                'id' => (int) $vendor['id_marketplace_vendor'],
                'code' => $vendor['code'],
                'name' => $vendor['name'],
                'status' => $vendor['status'],
                'email' => $vendor['email'],
                'commission' => number_format((float) $vendor['commission_percent'], 2, '.', ''),
                'description' => $vendor['description'],
                'shipping' => $parts === [] ? 'No shipping rule' : implode(' · ', $parts),
            ];
        }

        return $rows;
    }

    public function updateVendor(int $id, string $status, string $commission): void
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new \InvalidArgumentException('Status must be active or suspended.');
        }
        Money::basisPoints($commission);
        (new VendorRepository($this->db))->updateCommercials($id, $status, number_format((float) $commission, 2, '.', ''));
    }

    public function vendorDashboard(int $vendorId): array
    {
        $vendor = (new VendorRepository($this->db))->find($vendorId);
        if ($vendor === null) {
            throw new \RuntimeException('Vendor not found.');
        }
        $summary = (new DashboardRepository($this->db))->vendorSummary($vendorId);
        $catalog = new CatalogRepository($this->db);
        $lowestNet = [];
        foreach ($catalog->offerRows() as $row) {
            if ((string) $row['vendor_status'] !== 'active') {
                continue;
            }
            $net = Money::afterDiscount((int) $row['price_cents'], number_format((float) $row['discount_percent'], 2, '.', ''));
            $productKey = (int) $row['id_product'];
            if (!isset($lowestNet[$productKey]) || $net < $lowestNet[$productKey]) {
                $lowestNet[$productKey] = $net;
            }
        }
        $offers = [];
        foreach ($catalog->offerRows(null, $vendorId) as $row) {
            $available = max(0, (int) $row['quantity'] - (int) $row['reserved_quantity']);
            $discount = number_format((float) $row['discount_percent'], 2, '.', '');
            $net = Money::afterDiscount((int) $row['price_cents'], $discount);
            $market = $lowestNet[(int) $row['id_product']] ?? $net;
            $gap = $net - $market;
            $offers[] = [
                'sku' => $row['vendor_sku'],
                'title' => $row['title'],
                'price' => Money::euros($net),
                'discount' => $discount . '%',
                'on_hand' => (int) $row['quantity'],
                'reserved' => (int) $row['reserved_quantity'],
                'available' => $available,
                'low_stock' => $available <= (int) $row['low_stock_threshold'],
                'market_low' => Money::euros($market),
                'is_lowest' => $gap === 0,
                'gap_label' => $gap === 0 ? '€0.00' : Money::euros($gap),
                'position' => $gap === 0 ? 'Lowest' : 'Above market',
                'image' => $this->coverUrl((int) $row['id_product'], (string) $row['title'], (string) $row['vendor_sku']),
                'url' => \Context::getContext()->link->getProductLink((int) $row['id_product']),
            ];
        }

        $alerts = 0;
        foreach ($offers as $offer) {
            if ($offer['low_stock']) {
                ++$alerts;
            }
        }
        $summary['alerts'] = $alerts;

        return [
            'vendor' => $vendor,
            'summary' => $this->moneySummary($summary),
            'offers' => $offers,
            'orders' => $this->orderRows($vendorId),
            'sales' => $this->salesSeries($vendorId),
            'trends' => [
                'gmv' => $this->periodTrend('marketplace_vendor_order', 'COALESCE(SUM(`product_cents`), 0)', $vendorId),
                'orders' => $this->periodTrend('marketplace_vendor_order', 'COUNT(*)', $vendorId),
                'commission' => $this->periodTrend('marketplace_commission', 'COALESCE(SUM(`commission_cents`), 0)', $vendorId),
                'net' => $this->periodTrend('marketplace_commission', 'COALESCE(SUM(`net_cents`), 0)', $vendorId),
            ],
        ];
    }

    public function importCsv(string $csv, string $filename): array
    {
        $rows = (new CsvOfferParser())->parse($csv);
        if ($rows === []) {
            throw new \InvalidArgumentException('The CSV file has no data rows.');
        }
        $catalogRepo = new CatalogRepository($this->db);
        $vendorRepo = new VendorRepository($this->db);
        $products = [];
        foreach ($catalogRepo->masterProducts() as $product) {
            $products[] = new CatalogProduct((int) $product['id_product'], (string) $product['reference'], (string) $product['ean13'], (string) $product['name']);
        }
        $codes = [];
        $vendorsByCode = [];
        foreach ($vendorRepo->all() as $vendor) {
            $codes[] = (string) $vendor['code'];
            $vendorsByCode[(string) $vendor['code']] = (int) $vendor['id_marketplace_vendor'];
        }
        $plan = (new OfferImporter(new ProductMatcher()))->plan($rows, $products, $catalogRepo->existingOfferKeys(), $codes);
        $gateway = new PrestaShopProductGateway();
        $created = 0;
        $updated = 0;
        $failed = 0;
        $messages = [];

        foreach ($plan->actions as $action) {
            $row = $action->row;
            if ($row === null || !isset($vendorsByCode[$action->vendorCode])) {
                ++$failed;
                $messages[] = 'Line ' . $action->lineNumber . ': ' . $action->message;
                continue;
            }
            $vendorId = $vendorsByCode[$action->vendorCode];
            try {
                if ($action->type === ImportAction::REJECT) {
                    ++$failed;
                    $catalogRepo->recordMatch($vendorId, $row->sku, $row->ean, null, $action->match?->type->value ?? 'REJECT', $action->match?->matchedOn ?? 'NONE', $action->match?->candidateProductIds ?? []);
                    $messages[] = 'Line ' . $action->lineNumber . ': ' . $action->message;
                    continue;
                }
                if ($action->type === ImportAction::CREATE_MASTER) {
                    $productId = $gateway->upsert($row->sku, $row->ean, $row->title !== '' ? $row->title : $row->sku, $row->description, $row->listPriceCents);
                    $catalogRepo->recordMatch($vendorId, $row->sku, $row->ean, $productId, 'NO_MATCH', 'NONE', []);
                    $catalogRepo->upsertOffer($vendorId, $productId, $row->sku, $row->ean, $row->brand, $row->title, $row->description, $row->listPriceCents, $row->discountPercent, $row->weightGrams, $row->imageUrl, $row->stock);
                    ++$created;
                    continue;
                }
                if ($action->type === ImportAction::CREATE_OFFER) {
                    $catalogRepo->recordMatch($vendorId, $row->sku, $row->ean, $action->productId, 'EXACT_MATCH', $action->match?->matchedOn ?? 'SKU', $action->match?->candidateProductIds ?? []);
                    $catalogRepo->upsertOffer($vendorId, (int) $action->productId, $row->sku, $row->ean, $row->brand, $row->title, $row->description, $row->listPriceCents, $row->discountPercent, $row->weightGrams, $row->imageUrl, $row->stock);
                    ++$created;
                    continue;
                }
                $catalogRepo->upsertOffer($vendorId, $this->productIdForOffer((int) $action->existingOfferId), $row->sku, $row->ean, $row->brand, $row->title, $row->description, $row->listPriceCents, $row->discountPercent, $row->weightGrams, $row->imageUrl, $row->stock);
                ++$updated;
            } catch (\Throwable $exception) {
                ++$failed;
                $messages[] = 'Line ' . $action->lineNumber . ': ' . $exception->getMessage();
            }
        }

        $primaryVendor = $vendorsByCode[$rows[0]->vendorCode] ?? (int) array_values($vendorsByCode)[0];
        $now = Connection::quote(date('Y-m-d H:i:s'));
        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_import_job')
            . ' (`id_marketplace_vendor`, `filename`, `status`, `rows_total`, `rows_created`, `rows_updated`, `rows_failed`, `error_log`, `date_add`, `date_upd`) VALUES ('
            . $primaryVendor . ', ' . Connection::quote($filename) . ', ' . Connection::quote('completed') . ', '
            . count($rows) . ', ' . $created . ', ' . $updated . ', ' . $failed . ', '
            . Connection::quote(implode("\n", $messages)) . ', ' . $now . ', ' . $now . ')'
        );

        return [
            'total' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }

    public function importJobs(): array
    {
        return $this->db->all(
            'SELECT j.*, v.`name` AS vendor_name FROM ' . Connection::table('marketplace_import_job') . ' j
             INNER JOIN ' . Connection::table('marketplace_vendor') . ' v ON v.`id_marketplace_vendor` = j.`id_marketplace_vendor`
             ORDER BY j.`id_marketplace_import_job` DESC LIMIT 20'
        );
    }

    /**
     * @param list<array{id_product: int, quantity: int, name: string, reference: string}> $lines
     */
    private function cartInput(array $lines, string $postcode): CartInput
    {
        $cartLines = [];
        $productIds = [];
        foreach ($lines as $line) {
            $cartLines[] = new CartLine((int) $line['id_product'], (string) $line['name'], (int) $line['quantity']);
            $productIds[] = (int) $line['id_product'];
        }
        $options = (new CatalogRepository($this->db))->optionsForProducts($productIds, $postcode);

        return new CartInput($cartLines, $options, $postcode);
    }

    private function presentOptimization(OptimizationResult $result, ?OptimizationResult $baseline, ?CartInput $input = null): array
    {
        $vendors = [];
        foreach ($result->vendors as $vendor) {
            $lines = [];
            foreach ($vendor->lines as $line) {
                $lines[] = [
                    'product' => $line->productName,
                    'quantity' => $line->quantity,
                    'unit' => Money::euros($line->unitPriceCents),
                    'total' => Money::euros($line->lineTotalCents),
                ];
            }
            $vendors[] = [
                'code' => $vendor->vendorCode,
                'name' => $vendor->vendorName,
                'products' => Money::euros($vendor->productSubtotalCents),
                'shipping' => Money::euros($vendor->shippingCents),
                'total' => Money::euros($vendor->grandTotalCents()),
                'free_shipping' => $vendor->freeShippingApplied,
                'shipping_reason' => $vendor->shippingReason,
                'lines' => $lines,
            ];
        }

        $baselineView = null;
        if ($baseline instanceof OptimizationResult && $baseline->status !== OptimizationStatus::Infeasible) {
            $baselineView = [
                'products' => Money::euros($baseline->productSubtotalCents),
                'shipping' => Money::euros($baseline->shippingTotalCents),
                'total' => Money::euros($baseline->grandTotalCents),
                'product_cents' => $baseline->productSubtotalCents,
                'grand_cents' => $baseline->grandTotalCents,
            ];
        }

        $labels = [
            OptimizationStatus::OptimalVerified->value => 'Optimal verified',
            OptimizationStatus::FallbackNotProven->value => 'Best found, not fully proven',
            OptimizationStatus::Infeasible->value => 'Cannot fulfill',
        ];
        $savingsCents = 0;
        if ($baselineView !== null && $baselineView['grand_cents'] > $result->grandTotalCents) {
            $savingsCents = $baselineView['grand_cents'] - $result->grandTotalCents;
        }

        return [
            'status' => $result->status->value,
            'status_label' => $labels[$result->status->value] ?? $result->status->value,
            'explanation' => $result->explanation,
            'evaluated' => $result->combinationsEvaluated,
            'search_space' => $result->searchSpace,
            'stock_split' => $this->requiresStockSplit($input),
            'savings' => $savingsCents > 0 ? Money::euros($savingsCents) : '',
            'products' => Money::euros($result->productSubtotalCents),
            'shipping' => Money::euros($result->shippingTotalCents),
            'total' => Money::euros($result->grandTotalCents),
            'product_cents' => $result->productSubtotalCents,
            'grand_cents' => $result->grandTotalCents,
            'vendors' => $vendors,
            'baseline' => $baselineView,
            'shipping_wins' => $baselineView !== null && $result->productSubtotalCents > $baselineView['product_cents'] && $result->grandTotalCents < $baselineView['grand_cents'],
            'can_confirm' => $result->status !== OptimizationStatus::Infeasible,
        ];
    }

    private function moneySummary(array $summary): array
    {
        foreach (['gmv_cents', 'shipping_cents', 'checkout_cents', 'commission_cents', 'vendor_net_cents', 'net_cents'] as $key) {
            if (isset($summary[$key])) {
                $summary[str_replace('_cents', '', $key)] = Money::euros((int) $summary[$key]);
            }
        }

        return $summary;
    }

    private function orderRows(?int $vendorId): array
    {
        $rows = [];
        foreach ((new OrderRepository($this->db))->recent(30, $vendorId) as $order) {
            $lines = [];
            foreach ($order['lines'] as $line) {
                $lines[] = $line['quantity'] . ' × ' . $line['product_name'] . ' (' . Money::euros((int) $line['line_total_cents']) . ')';
            }
            $name = trim((string) ($order['firstname'] ?? '') . ' ' . (string) ($order['lastname'] ?? ''));
            $rows[] = [
                'parent' => $order['parent_reference'],
                'reference' => $order['reference'],
                'vendor' => $order['vendor_name'],
                'code' => $order['code'],
                'status' => $order['optimization_status'],
                'products' => Money::euros((int) $order['product_cents']),
                'shipping' => Money::euros((int) $order['shipping_cents']),
                'total' => Money::euros((int) $order['grand_cents']),
                'product_cents' => (int) $order['product_cents'],
                'date' => $order['date_add'],
                'postcode' => (string) ($order['postcode'] ?? ''),
                'buyer' => ($name !== '' ? $name : 'Guest') . ' · ' . (string) ($order['postcode'] ?? ''),
                'payment' => (string) ($order['payment_method'] ?? '') !== '' ? (string) $order['payment_method'] : '—',
                'shop' => (string) ($order['shop_reference'] ?? '') !== '' ? (string) $order['shop_reference'] : '—',
                'badge' => $this->orderBadge((string) ($order['order_status'] ?? 'confirmed')),
                'items' => implode(', ', $lines),
                'lines' => $lines,
                'commission' => Money::euros((int) ($order['commission_cents'] ?? 0)),
                'net' => Money::euros((int) ($order['net_cents'] ?? 0)),
            ];
        }

        return $rows;
    }

    /**
     * @return array{direction: string, percent: string, text: string}
     */
    private function periodTrend(string $table, string $expression, ?int $vendorId): array
    {
        $currentStart = date('Y-m-01 00:00:00');
        $previousStart = date('Y-m-01 00:00:00', strtotime('first day of last month'));
        $previousEnd = date('Y-m-t 23:59:59', strtotime('last day of last month'));
        $vendorSql = $vendorId === null ? '' : ' AND `id_marketplace_vendor` = ' . (int) $vendorId;
        $from = ' FROM ' . Connection::table($table) . ' WHERE 1' . $vendorSql;
        $current = (int) $this->db->value('SELECT ' . $expression . $from . ' AND `date_add` >= ' . Connection::quote($currentStart));
        $previous = (int) $this->db->value('SELECT ' . $expression . $from . ' AND `date_add` >= ' . Connection::quote($previousStart) . ' AND `date_add` <= ' . Connection::quote($previousEnd));
        if ($previous === 0) {
            return [
                'direction' => $current > 0 ? 'new' : 'flat',
                'percent' => $current > 0 ? 'New' : '—',
                'text' => $current > 0 ? 'First recorded period' : 'No earlier period',
            ];
        }
        $change = (($current - $previous) / $previous) * 100;
        $rounded = number_format($change, 1, '.', '');

        return [
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
            'percent' => ($change > 0 ? '+' : '') . $rounded . '%',
            'text' => 'vs last month',
        ];
    }

    /**
     * @return list<array{name: string, code: string, status: string, orders: int, gmv: string, average: string, commission: string, share: int, fulfillment: int}>
     */
    private function vendorPerformance(): array
    {
        $rows = $this->db->all(
            'SELECT mv.`name`, mv.`code`, mv.`status`,
                    COUNT(v.`id_marketplace_vendor_order`) AS orders,
                    COALESCE(SUM(v.`product_cents`), 0) AS gmv_cents,
                    COALESCE(SUM(v.`grand_cents`), 0) AS checkout_cents,
                    COALESCE(SUM(c.`commission_cents`), 0) AS commission_cents,
                    SUM(CASE WHEN o.`status` IN (\'confirmed\', \'paid\', \'completed\') THEN 1 ELSE 0 END) AS confirmed_orders
             FROM ' . Connection::table('marketplace_vendor') . ' mv
             LEFT JOIN ' . Connection::table('marketplace_vendor_order') . ' v ON v.`id_marketplace_vendor` = mv.`id_marketplace_vendor`
             LEFT JOIN ' . Connection::table('marketplace_order') . ' o ON o.`id_marketplace_order` = v.`id_marketplace_order`
             LEFT JOIN ' . Connection::table('marketplace_commission') . ' c ON c.`id_marketplace_vendor_order` = v.`id_marketplace_vendor_order`
             GROUP BY mv.`id_marketplace_vendor`, mv.`name`, mv.`code`, mv.`status`
             ORDER BY gmv_cents DESC, mv.`name` ASC'
        );
        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['gmv_cents'];
        }
        $performance = [];
        foreach ($rows as $row) {
            $orders = (int) $row['orders'];
            $gmv = (int) $row['gmv_cents'];
            $performance[] = [
                'name' => (string) $row['name'],
                'code' => (string) $row['code'],
                'status' => (string) $row['status'],
                'orders' => $orders,
                'gmv' => Money::euros($gmv),
                'average' => $orders > 0 ? Money::euros((int) round(((int) $row['checkout_cents']) / $orders)) : Money::euros(0),
                'commission' => Money::euros((int) $row['commission_cents']),
                'share' => $total > 0 ? (int) round(($gmv / $total) * 100) : 0,
                'fulfillment' => $orders > 0 ? (int) round((((int) $row['confirmed_orders']) / $orders) * 100) : 0,
            ];
        }

        return $performance;
    }

    /**
     * @return array{labels: list<string>, orders: list<int>, gmv: list<int>}
     */
    private function ordersByVendor(): array
    {
        $rows = $this->db->all(
            'SELECT mv.`code`, COUNT(v.`id_marketplace_vendor_order`) AS orders, COALESCE(SUM(v.`product_cents`), 0) AS gmv_cents
             FROM ' . Connection::table('marketplace_vendor') . ' mv
             LEFT JOIN ' . Connection::table('marketplace_vendor_order') . ' v ON v.`id_marketplace_vendor` = mv.`id_marketplace_vendor`
             GROUP BY mv.`id_marketplace_vendor`, mv.`code`
             ORDER BY mv.`code` ASC'
        );
        $labels = [];
        $orders = [];
        $gmv = [];
        foreach ($rows as $row) {
            $labels[] = (string) $row['code'];
            $orders[] = (int) $row['orders'];
            $gmv[] = (int) round(((int) $row['gmv_cents']) / 100);
        }

        return ['labels' => $labels, 'orders' => $orders, 'gmv' => $gmv];
    }

    private function orderBadge(string $status): string
    {
        return match ($status) {
            'paid' => 'Paid',
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'confirmed', 'completed' => 'Completed',
            'awaiting_payment' => 'Awaiting payment',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function productBrand(int $productId): string
    {
        return $this->db->value(
            'SELECT `brand` FROM ' . Connection::table('marketplace_vendor_offer')
            . ' WHERE `id_product` = ' . (int) $productId . ' AND `brand` IS NOT NULL AND `brand` <> \'\''
        );
    }

    private function fingerprint(CartInput $input): string
    {
        $offers = [];
        foreach ($input->offersByProductId as $productId => $options) {
            foreach ($options as $option) {
                $offers[] = [
                    $productId,
                    $option->offerId,
                    $option->unitPriceCents,
                    $option->stock,
                    $option->shipping->baseCents,
                    $option->shipping->freeThresholdCents,
                    $option->shipping->surchargeCentsPerKg,
                    $option->shipping->postcodePrefixes,
                ];
            }
        }

        return json_encode([$input->postcode, $input->lines, $offers], JSON_THROW_ON_ERROR);
    }

    private function requiresStockSplit(?CartInput $input): bool
    {
        if (!$input instanceof CartInput) {
            return false;
        }
        $maxStock = [];
        foreach ($input->offersByProductId as $productId => $options) {
            $max = 0;
            foreach ($options as $option) {
                $max = max($max, $option->stock);
            }
            $maxStock[(int) $productId] = $max;
        }
        foreach ($input->lines as $line) {
            if ($line->quantity > ($maxStock[$line->productId] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, string>>
     */
    private function settlementRows(): array
    {
        $rows = $this->db->all(
            'SELECT s.*, v.`name` AS vendor_name, v.`code`
             FROM ' . Connection::table('marketplace_settlement') . ' s
             INNER JOIN ' . Connection::table('marketplace_vendor') . ' v ON v.`id_marketplace_vendor` = s.`id_marketplace_vendor`
             ORDER BY s.`period_start` DESC, v.`code` ASC
             LIMIT 12'
        );
        $view = [];
        foreach ($rows as $row) {
            $view[] = [
                'vendor' => (string) $row['vendor_name'],
                'code' => (string) $row['code'],
                'period' => (string) $row['period_start'] . ' – ' . (string) $row['period_end'],
                'status' => (string) $row['status'],
                'gross' => Money::euros((int) $row['gross_cents']),
                'commission' => Money::euros((int) $row['commission_cents']),
                'net' => Money::euros((int) $row['net_cents']),
            ];
        }

        return $view;
    }

    /**
     * @param list<array<string, mixed>> $offers
     */
    private function compareAt(array $offers, ?int $starting): ?string
    {
        if ($starting === null) {
            return null;
        }
        foreach ($offers as $offer) {
            if ((int) ($offer['net_cents'] ?? 0) === $starting && ($offer['discount'] ?? '0.00') !== '0.00') {
                return (string) $offer['list'];
            }
        }

        return null;
    }

    private function shopperDescription(string $sku, string $stored): string
    {
        $copy = [
            'COFFEE-1KG' => 'Whole-bean coffee, one kilogram. Three vendors sell the same lot, each with their own price and stock.',
            'MUESLI-1KG' => 'Toasted muesli, one kilogram. Often paired with honey; the delivered total depends on who ships both.',
            'HONEY-500' => 'Blossom honey, 500 grams, in a glass jar.',
            'OIL-500' => 'Extra virgin olive oil, 500 millilitres. Harbor Bean lists it with a 10% discount.',
            'OATS-5KG' => 'Rolled oats in a 5 kilogram sack. Larger quantities may ship from more than one vendor.',
        ];

        return $copy[$sku] ?? $stored;
    }

    private function defaultCategoryName(int $productId): string
    {
        $langId = (int) \Configuration::get('PS_LANG_DEFAULT');

        return $this->db->value(
            'SELECT cl.`name` FROM `' . _DB_PREFIX_ . 'category_lang` cl
             INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_category_default` = cl.`id_category`
             WHERE p.`id_product` = ' . (int) $productId . ' AND cl.`id_lang` = ' . $langId
        );
    }

    /**
     * @return array{labels: list<string>, gmv: list<int>, orders: list<int>}
     */
    private function salesSeries(?int $vendorId): array
    {
        $table = $vendorId === null ? 'marketplace_order' : 'marketplace_vendor_order';
        $sql = 'SELECT DATE(`date_add`) AS day, SUM(`product_cents`) AS cents, COUNT(*) AS orders FROM '
            . Connection::table($table);
        if ($vendorId !== null) {
            $sql .= ' WHERE `id_marketplace_vendor` = ' . (int) $vendorId;
        }
        $sql .= ' GROUP BY DATE(`date_add`) ORDER BY day ASC';
        $labels = [];
        $gmv = [];
        $orders = [];
        foreach ($this->db->all($sql) as $row) {
            $labels[] = (string) $row['day'];
            $gmv[] = (int) round(((int) $row['cents']) / 100);
            $orders[] = (int) $row['orders'];
        }

        return ['labels' => $labels, 'gmv' => $gmv, 'orders' => $orders];
    }

    /**
     * @return array{labels: list<string>, values: list<int>}
     */
    private function commissionSeries(): array
    {
        $rows = $this->db->all(
            'SELECT DATE(`date_add`) AS day, SUM(`commission_cents`) AS cents FROM '
            . Connection::table('marketplace_commission')
            . ' GROUP BY DATE(`date_add`) ORDER BY day ASC'
        );
        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $labels[] = (string) $row['day'];
            $values[] = (int) round(((int) $row['cents']) / 100);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private function coverUrl(int $productId, string $name, string $sku = ''): string
    {
        $photos = [
            'COFFEE-1KG' => 'coffee.jpg',
            'MUESLI-1KG' => 'muesli.jpg',
            'OATS-5KG' => 'oats.jpg',
            'OIL-500' => 'oil.jpg',
            'HONEY-500' => 'honey.jpg',
            'PASTA-500' => 'pasta.jpg',
            'TEA-100' => 'tea.jpg',
            'WATER-6' => 'water.jpg',
            'SOAP-500' => 'soap.jpg',
            'TOWEL-2' => 'towel.jpg',
            'MUG-01' => 'mug.jpg',
            'RICE-2KG' => 'rice.jpg',
            'CHOC-100' => 'chocolate.jpg',
            'CREAM-75' => 'cream.jpg',
        ];
        if (isset($photos[$sku])) {
            return rtrim((string) __PS_BASE_URI__, '/') . '/modules/marketplacecore/views/img/' . $photos[$sku];
        }

        try {
            $cover = \Image::getCover($productId);
            if (!is_array($cover) || empty($cover['id_image'])) {
                return '';
            }

            return (string) \Context::getContext()->link->getImageLink(\Tools::link_rewrite($name), (string) $cover['id_image'], 'home_default');
        } catch (\Throwable) {
            return '';
        }
    }

    private function productIdForOffer(int $offerId): int
    {
        return (int) $this->db->value(
            'SELECT `id_product` FROM ' . Connection::table('marketplace_vendor_offer')
            . ' WHERE `id_marketplace_vendor_offer` = ' . (int) $offerId
        );
    }
}
