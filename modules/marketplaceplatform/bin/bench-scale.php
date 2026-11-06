<?php

declare(strict_types=1);

/**
 * Synthetic catalog benchmark. The database name is the first argument.
 * It must already contain the marketplace schema and must not be the shop database.
 */

require_once '/var/www/html/modules/marketplacecore/src/Autoload.php';
Marketplace\Core\Autoload::register();

use Marketplace\Optimizer\Dto\CartInput;
use Marketplace\Optimizer\Dto\CartLine;
use Marketplace\Optimizer\Dto\OfferOption;
use Marketplace\Optimizer\Service\CartOptimizer;
use Marketplace\Shipping\Dto\ShippingRule;
use Marketplace\Shipping\Service\ShippingCalculator;

$database = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_]+$/', $database) || $database === 'prestashop') {
    fwrite(STDERR, "Refusing to benchmark the shop database.\n");
    exit(1);
}

$pdo = new PDO('mysql:host=db;dbname=' . $database . ';charset=utf8mb4', 'prestashop', 'prestashop', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$now = date('Y-m-d H:i:s');
$vendors = 30;
$products = 4000;

$existing = (int) $pdo->query('SELECT COUNT(*) FROM ps_marketplace_vendor')->fetchColumn();
if ($existing === 0) {
    $vendorInsert = $pdo->prepare('INSERT INTO ps_marketplace_vendor (code, name, slug, status, commission_percent, email, password_hash, date_add, date_upd) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ruleInsert = $pdo->prepare('INSERT INTO ps_marketplace_shipping_rule (id_marketplace_vendor, name, base_cents, free_threshold_cents, surcharge_cents_per_kg, postcode_prefixes, active, date_add, date_upd) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
    $hash = password_hash('vendor-password-1', PASSWORD_DEFAULT);
    for ($vendor = 1; $vendor <= $vendors; $vendor++) {
        $code = 'V' . $vendor;
        $vendorInsert->execute([$code, 'Vendor ' . $vendor, 'vendor-' . $vendor, 'active', '10.00', 'vendor' . $vendor . '@example.com', $hash, $now, $now]);
        $ruleInsert->execute([(int) $pdo->lastInsertId(), 'Primary', 300, 5000, 100, '', $now, $now]);
    }

    $offerInsert = $pdo->prepare('INSERT INTO ps_marketplace_vendor_offer (id_marketplace_vendor, id_product, vendor_sku, title, price_cents, discount_percent, weight_grams, active, date_add, date_upd) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
    $stockInsert = $pdo->prepare('INSERT INTO ps_marketplace_vendor_stock (id_marketplace_vendor_offer, quantity, reserved_quantity, low_stock_threshold, date_upd) VALUES (?, ?, 0, 3, ?)');
    $pdo->beginTransaction();
    $written = 0;
    for ($product = 1; $product <= $products; $product++) {
        for ($vendor = 1; $vendor <= $vendors; $vendor++) {
            $price = 1000 + (($vendor * 17 + $product) % 800);
            $offerInsert->execute([$vendor, $product, 'P' . $product, 'Product ' . $product, $price, '0.00', 500, $now, $now]);
            $stockInsert->execute([(int) $pdo->lastInsertId(), 5 + ($product % 20), $now]);
            if (++$written % 2000 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }
    }
    $pdo->commit();
}

$offers = (int) $pdo->query('SELECT COUNT(*) FROM ps_marketplace_vendor_offer')->fetchColumn();
$explain = $pdo->query('EXPLAIN FORMAT=JSON SELECT o.id_marketplace_vendor_offer, o.price_cents, s.quantity, s.reserved_quantity, v.code FROM ps_marketplace_vendor_offer o INNER JOIN ps_marketplace_vendor_stock s ON s.id_marketplace_vendor_offer = o.id_marketplace_vendor_offer INNER JOIN ps_marketplace_vendor v ON v.id_marketplace_vendor = o.id_marketplace_vendor WHERE o.id_product = 2500 AND o.active = 1 AND v.status = \'active\'')->fetchColumn();
$plan = json_decode((string) $explain, true);
$query = $pdo->prepare('SELECT o.id_marketplace_vendor_offer, o.id_marketplace_vendor, o.price_cents, o.weight_grams, s.quantity, s.reserved_quantity, v.code, v.name FROM ps_marketplace_vendor_offer o INNER JOIN ps_marketplace_vendor_stock s ON s.id_marketplace_vendor_offer = o.id_marketplace_vendor_offer INNER JOIN ps_marketplace_vendor v ON v.id_marketplace_vendor = o.id_marketplace_vendor WHERE o.id_product = ? AND o.active = 1 AND v.status = \'active\'');
$started = hrtime(true);
$rows = [];
for ($i = 0; $i < 100; $i++) {
    $query->execute([($i % $products) + 1]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
}
$lookupSeconds = (hrtime(true) - $started) / 1_000_000_000;

$started = hrtime(true);
for ($i = 0; $i < 50; $i++) {
    $offset = $i * 20;
    $page = $pdo->query('SELECT o.id_marketplace_vendor_offer, o.price_cents FROM ps_marketplace_vendor_offer o WHERE o.active = 1 ORDER BY o.price_cents, o.id_marketplace_vendor_offer LIMIT 20 OFFSET ' . $offset);
    $page->fetchAll(PDO::FETCH_ASSOC);
}
$pageSeconds = (hrtime(true) - $started) / 1_000_000_000;
$started = hrtime(true);
for ($i = 0; $i < 20; $i++) {
    $deep = $pdo->query('SELECT o.id_marketplace_vendor_offer, o.price_cents FROM ps_marketplace_vendor_offer o WHERE o.active = 1 ORDER BY o.price_cents, o.id_marketplace_vendor_offer LIMIT 20 OFFSET 100000');
    $deep->fetchAll(PDO::FETCH_ASSOC);
}
$deepSeconds = (hrtime(true) - $started) / 1_000_000_000;
$pageExplain = $pdo->query('EXPLAIN FORMAT=JSON SELECT o.id_marketplace_vendor_offer, o.price_cents FROM ps_marketplace_vendor_offer o WHERE o.active = 1 ORDER BY o.price_cents, o.id_marketplace_vendor_offer LIMIT 20 OFFSET 100000')->fetchColumn();

$options = [];
foreach ([1, 2] as $productId) {
    $query->execute([$productId]);
    $options[$productId] = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $options[$productId][] = new OfferOption(
            (int) $row['id_marketplace_vendor_offer'],
            (int) $row['id_marketplace_vendor'],
            (string) $row['code'],
            (string) $row['name'],
            $productId,
            (int) $row['price_cents'],
            (int) $row['quantity'] - (int) $row['reserved_quantity'],
            (int) $row['weight_grams'],
            new ShippingRule((int) $row['id_marketplace_vendor'], 300, 5000, 100, []),
        );
    }
}
$cart = new CartInput(
    [new CartLine(1, 'Product 1', 2), new CartLine(2, 'Product 2', 1)],
    $options,
    '10115',
);
$optimizer = new CartOptimizer(new ShippingCalculator());
$started = hrtime(true);
$result = null;
for ($i = 0; $i < 20; $i++) {
    $result = $optimizer->optimize($cart);
}
$optimizeSeconds = (hrtime(true) - $started) / 1_000_000_000;

echo json_encode([
    'vendors' => $vendors,
    'products' => $products,
    'offers' => $offers,
    'candidates_per_lookup' => count($rows),
    'lookup_100_seconds' => $lookupSeconds,
    'lookup_mean_ms' => ($lookupSeconds / 100) * 1000,
    'page_50_seconds' => $pageSeconds,
    'page_mean_ms' => ($pageSeconds / 50) * 1000,
    'deep_page_20_seconds' => $deepSeconds,
    'deep_page_mean_ms' => ($deepSeconds / 20) * 1000,
    'page_plan' => explainAccess(json_decode((string) $pageExplain, true)),
    'optimizer_runs' => 20,
    'optimizer_seconds' => $optimizeSeconds,
    'optimizer_mean_ms' => ($optimizeSeconds / 20) * 1000,
    'optimizer_status' => $result?->status->value,
    'optimizer_grand_cents' => $result?->grandTotalCents,
    'optimizer_search_space' => $result?->searchSpace,
    'peak_memory_mb' => memory_get_peak_usage(true) / 1048576,
    'explain_access' => explainAccess($plan),
], JSON_UNESCAPED_SLASHES) . "\n";

function explainAccess(mixed $plan): string
{
    $encoded = json_encode($plan);
    if (!is_string($encoded)) {
        return 'unavailable';
    }
    if (str_contains($encoded, 'mp_offer_product_active') || str_contains($encoded, 'mp_offer_product')) {
        return 'offer_product_index';
    }
    if (str_contains($encoded, '"type": "ALL"') || str_contains($encoded, '"access_type": "ALL"')) {
        return 'full_scan';
    }

    return 'other';
}
