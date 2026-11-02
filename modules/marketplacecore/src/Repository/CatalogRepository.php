<?php

declare(strict_types=1);

namespace Marketplace\Core\Repository;

use Marketplace\Core\Database\Connection;
use Marketplace\Core\Domain\Money;
use Marketplace\Optimizer\Dto\OfferOption;
use Marketplace\Shipping\Dto\ShippingRule;
use Marketplace\Shipping\Service\ShippingRuleSelector;

final class CatalogRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function productIdByReference(string $sku): int
    {
        return (int) $this->db->value(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `reference` = ' . Connection::quote($sku)
        );
    }

    public function productName(int $productId): string
    {
        $langId = (int) \Configuration::get('PS_LANG_DEFAULT');
        $shopId = (int) \Configuration::get('PS_SHOP_DEFAULT');

        return $this->db->value(
            'SELECT `name` FROM `' . _DB_PREFIX_ . 'product_lang` WHERE `id_product` = ' . (int) $productId
            . ' AND `id_lang` = ' . $langId . ' AND `id_shop` = ' . $shopId
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function masterProducts(): array
    {
        $langId = (int) \Configuration::get('PS_LANG_DEFAULT');
        $shopId = (int) \Configuration::get('PS_SHOP_DEFAULT');

        return $this->db->all(
            'SELECT DISTINCT p.`id_product`, p.`reference`, p.`ean13`, pl.`name`
             FROM ' . Connection::table('marketplace_vendor_offer') . ' o
             INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = o.`id_product`
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $langId . ' AND pl.`id_shop` = ' . $shopId . '
             WHERE o.`active` = 1
             ORDER BY pl.`name` ASC'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function offerRows(?int $productId = null, ?int $vendorId = null, string $postcode = ''): array
    {
        $sql = 'SELECT o.*, s.`quantity`, s.`reserved_quantity`, s.`low_stock_threshold`,
                       v.`code`, v.`name` AS vendor_name, v.`status` AS vendor_status, v.`commission_percent`
                FROM ' . Connection::table('marketplace_vendor_offer') . ' o
                INNER JOIN ' . Connection::table('marketplace_vendor_stock') . ' s
                  ON s.`id_marketplace_vendor_offer` = o.`id_marketplace_vendor_offer`
                INNER JOIN ' . Connection::table('marketplace_vendor') . ' v
                  ON v.`id_marketplace_vendor` = o.`id_marketplace_vendor`
                WHERE o.`active` = 1';
        if ($productId !== null) {
            $sql .= ' AND o.`id_product` = ' . (int) $productId;
        }
        if ($vendorId !== null) {
            $sql .= ' AND o.`id_marketplace_vendor` = ' . (int) $vendorId;
        }
        $sql .= ' ORDER BY o.`id_product` ASC, v.`code` ASC';

        return $this->withChosenRule($this->db->all($sql), $postcode);
    }

    /**
     * @param list<int> $productIds
     * @return array<int, list<OfferOption>>
     */
    public function optionsForProducts(array $productIds, string $postcode = ''): array
    {
        $grouped = [];
        foreach ($productIds as $productId) {
            $grouped[(int) $productId] = [];
        }
        foreach ($this->offerRows(null, null, $postcode) as $row) {
            $productId = (int) $row['id_product'];
            if (!array_key_exists($productId, $grouped)) {
                continue;
            }
            if ((string) $row['vendor_status'] !== 'active') {
                continue;
            }
            $available = max(0, (int) $row['quantity'] - (int) $row['reserved_quantity']);
            $grouped[$productId][] = new OfferOption(
                (int) $row['id_marketplace_vendor_offer'],
                (int) $row['id_marketplace_vendor'],
                (string) $row['code'],
                (string) $row['vendor_name'],
                $productId,
                Money::afterDiscount((int) $row['price_cents'], number_format((float) $row['discount_percent'], 2, '.', '')),
                $available,
                (int) $row['weight_grams'],
                $this->rule($row),
            );
        }

        return $grouped;
    }

    public function upsertOffer(
        int $vendorId,
        int $productId,
        string $sku,
        string $ean,
        string $brand,
        string $title,
        string $description,
        int $priceCents,
        string $discount,
        int $weightGrams,
        string $imageUrl,
        int $stock,
        bool $resetReservations = false,
    ): int {
        $now = Connection::quote(date('Y-m-d H:i:s'));
        $existing = $this->db->one(
            'SELECT `id_marketplace_vendor_offer` FROM ' . Connection::table('marketplace_vendor_offer')
            . ' WHERE `id_marketplace_vendor` = ' . (int) $vendorId
            . ' AND `vendor_sku` = ' . Connection::quote($sku)
        );
        if ($existing === null && $ean !== '') {
            $existing = $this->db->one(
                'SELECT `id_marketplace_vendor_offer` FROM ' . Connection::table('marketplace_vendor_offer')
                . ' WHERE `id_marketplace_vendor` = ' . (int) $vendorId
                . ' AND `ean13` = ' . Connection::quote($ean)
            );
        }

        if ($existing) {
            $id = (int) $existing['id_marketplace_vendor_offer'];
            $this->db->exec(
                'UPDATE ' . Connection::table('marketplace_vendor_offer')
                . ' SET `id_product` = ' . (int) $productId
                . ', `vendor_sku` = ' . Connection::quote($sku)
                . ', `ean13` = ' . Connection::quote($ean)
                . ', `brand` = ' . Connection::quote($brand)
                . ', `title` = ' . Connection::quote($title)
                . ', `description` = ' . Connection::quote($description)
                . ', `price_cents` = ' . (int) $priceCents
                . ', `discount_percent` = ' . Connection::quote($discount)
                . ', `weight_grams` = ' . (int) $weightGrams
                . ', `image_url` = ' . Connection::quote($imageUrl)
                . ', `active` = 1, `date_upd` = ' . $now
                . ' WHERE `id_marketplace_vendor_offer` = ' . $id
            );
        } else {
            $this->db->exec(
                'INSERT INTO ' . Connection::table('marketplace_vendor_offer')
                . ' (`id_marketplace_vendor`, `id_product`, `vendor_sku`, `ean13`, `brand`, `title`, `description`, `price_cents`, `discount_percent`, `weight_grams`, `image_url`, `active`, `date_add`, `date_upd`) VALUES ('
                . (int) $vendorId . ', ' . (int) $productId . ', ' . Connection::quote($sku) . ', ' . Connection::quote($ean) . ', '
                . Connection::quote($brand) . ', ' . Connection::quote($title) . ', ' . Connection::quote($description) . ', '
                . (int) $priceCents . ', ' . Connection::quote($discount) . ', ' . (int) $weightGrams . ', '
                . Connection::quote($imageUrl) . ', 1, ' . $now . ', ' . $now . ')'
            );
            $id = $this->db->lastId();
        }

        $this->replaceStock($id, $stock, $resetReservations);

        return $id;
    }

    public function replaceStock(int $offerId, int $quantity, bool $resetReservations = false): void
    {
        $now = Connection::quote(date('Y-m-d H:i:s'));
        $existing = $this->db->one(
            'SELECT `id_marketplace_vendor_stock` FROM ' . Connection::table('marketplace_vendor_stock')
            . ' WHERE `id_marketplace_vendor_offer` = ' . (int) $offerId
        );
        if ($existing) {
            $reservedSql = $resetReservations ? ', `reserved_quantity` = 0' : '';
            $this->db->exec(
                'UPDATE ' . Connection::table('marketplace_vendor_stock')
                . ' SET `quantity` = ' . (int) $quantity
                . $reservedSql
                . ', `date_upd` = ' . $now
                . ' WHERE `id_marketplace_vendor_offer` = ' . (int) $offerId
            );
            if ($resetReservations) {
                $this->db->exec(
                    'DELETE FROM ' . Connection::table('marketplace_stock_reservation')
                    . ' WHERE `id_marketplace_vendor_offer` = ' . (int) $offerId
                );
            }

            return;
        }

        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_vendor_stock')
            . ' (`id_marketplace_vendor_offer`, `quantity`, `reserved_quantity`, `low_stock_threshold`, `date_upd`) VALUES ('
            . (int) $offerId . ', ' . (int) $quantity . ', 0, 3, ' . $now . ')'
        );
    }

    /**
     * @return list<array{id: int, vendor_code: string, sku: string, ean: string}>
     */
    public function existingOfferKeys(): array
    {
        $rows = $this->db->all(
            'SELECT o.`id_marketplace_vendor_offer` AS id, v.`code` AS vendor_code, o.`vendor_sku` AS sku, o.`ean13` AS ean
             FROM ' . Connection::table('marketplace_vendor_offer') . ' o
             INNER JOIN ' . Connection::table('marketplace_vendor') . ' v ON v.`id_marketplace_vendor` = o.`id_marketplace_vendor`'
        );
        $keys = [];
        foreach ($rows as $row) {
            $keys[] = [
                'id' => (int) $row['id'],
                'vendor_code' => (string) $row['vendor_code'],
                'sku' => (string) $row['sku'],
                'ean' => (string) $row['ean'],
            ];
        }

        return $keys;
    }

    public function recordMatch(int $vendorId, string $sku, string $ean, ?int $productId, string $type, string $key, array $candidates): void
    {
        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_product_match')
            . ' (`id_marketplace_vendor`, `vendor_sku`, `ean13`, `id_product`, `match_type`, `match_key`, `candidate_product_ids`, `date_add`) VALUES ('
            . (int) $vendorId . ', ' . Connection::quote($sku) . ', ' . Connection::quote($ean) . ', '
            . ($productId === null ? 'NULL' : (string) (int) $productId) . ', '
            . Connection::quote($type) . ', ' . Connection::quote($key) . ', '
            . Connection::quote(implode(',', $candidates)) . ', ' . Connection::quote(date('Y-m-d H:i:s')) . ')'
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withChosenRule(array $rows, string $postcode): array
    {
        $rules = [];
        foreach ($this->db->all(
            'SELECT * FROM ' . Connection::table('marketplace_shipping_rule')
            . ' WHERE `active` = 1 ORDER BY `id_marketplace_shipping_rule` ASC'
        ) as $rule) {
            $rules[(int) $rule['id_marketplace_vendor']][] = $rule;
        }
        $selector = new ShippingRuleSelector();
        $attached = [];
        foreach ($rows as $row) {
            $chosen = $selector->choose($rules[(int) $row['id_marketplace_vendor']] ?? [], $postcode);
            $row['base_cents'] = (int) ($chosen['base_cents'] ?? 0);
            $row['free_threshold_cents'] = $chosen === null ? null : $chosen['free_threshold_cents'];
            $row['surcharge_cents_per_kg'] = (int) ($chosen['surcharge_cents_per_kg'] ?? 0);
            $row['postcode_prefixes'] = (string) ($chosen['postcode_prefixes'] ?? '');
            $attached[] = $row;
        }

        return $attached;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rule(array $row): ShippingRule
    {
        $prefixes = trim((string) ($row['postcode_prefixes'] ?? ''));
        $threshold = $row['free_threshold_cents'];

        return new ShippingRule(
            (int) $row['id_marketplace_vendor'],
            (int) ($row['base_cents'] ?? 0),
            $threshold === null || $threshold === '' ? null : (int) $threshold,
            (int) ($row['surcharge_cents_per_kg'] ?? 0),
            $prefixes === '' ? [] : explode(',', $prefixes),
        );
    }
}
