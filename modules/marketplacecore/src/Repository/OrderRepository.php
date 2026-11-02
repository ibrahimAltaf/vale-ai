<?php

declare(strict_types=1);

namespace Marketplace\Core\Repository;

use Marketplace\Core\Database\Connection;
use Marketplace\Orders\Dto\SplitOrderPlan;

final class OrderRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function nextReference(): string
    {
        $rows = $this->db->all(
            'SELECT `reference` FROM ' . Connection::table('marketplace_order')
            . ' ORDER BY `id_marketplace_order` DESC LIMIT 1 FOR UPDATE'
        );
        $current = isset($rows[0]['reference']) ? (string) $rows[0]['reference'] : '';
        $next = $current === '' ? 1001 : ((int) $current + 1);

        return (string) $next;
    }

    /**
     * @param array<int, int> $reservationIdByOfferId
     */
    public function persist(SplitOrderPlan $plan, string $note, array $reservationIdByOfferId): int
    {
        $now = Connection::quote(date('Y-m-d H:i:s'));
        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_order')
            . ' (`reference`, `postcode`, `product_cents`, `shipping_cents`, `grand_cents`, `optimization_status`, `optimization_note`, `status`, `date_add`) VALUES ('
            . Connection::quote($plan->parentReference) . ', ' . Connection::quote($plan->postcode) . ', '
            . (int) $plan->productCents . ', ' . (int) $plan->shippingCents . ', ' . (int) $plan->grandCents . ', '
            . Connection::quote($plan->optimizationStatus) . ', ' . Connection::quote($note) . ', '
            . Connection::quote('confirmed') . ', ' . $now . ')'
        );
        $parentId = $this->db->lastId();

        foreach ($plan->vendorOrders as $vendorOrder) {
            $this->db->exec(
                'INSERT INTO ' . Connection::table('marketplace_vendor_order')
                . ' (`id_marketplace_order`, `reference`, `parent_reference`, `id_marketplace_vendor`, `product_cents`, `shipping_cents`, `grand_cents`, `postcode`, `optimization_status`, `status`, `date_add`) VALUES ('
                . $parentId . ', ' . Connection::quote($vendorOrder->reference) . ', ' . Connection::quote($plan->parentReference) . ', '
                . (int) $vendorOrder->vendorId . ', ' . (int) $vendorOrder->productCents . ', ' . (int) $vendorOrder->shippingCents . ', '
                . (int) $vendorOrder->grandCents . ', ' . Connection::quote($plan->postcode) . ', '
                . Connection::quote($plan->optimizationStatus) . ', ' . Connection::quote('confirmed') . ', ' . $now . ')'
            );
            $vendorOrderId = $this->db->lastId();
            foreach ($vendorOrder->lines as $line) {
                $this->db->exec(
                    'INSERT INTO ' . Connection::table('marketplace_vendor_order_line')
                    . ' (`id_marketplace_vendor_order`, `id_marketplace_vendor_offer`, `id_product`, `product_name`, `quantity`, `unit_price_cents`, `line_total_cents`) VALUES ('
                    . $vendorOrderId . ', ' . (int) $line->offerId . ', ' . (int) $line->productId . ', '
                    . Connection::quote($line->productName) . ', ' . (int) $line->quantity . ', '
                    . (int) $line->unitPriceCents . ', ' . (int) $line->lineTotalCents . ')'
                );
                if (isset($reservationIdByOfferId[$line->offerId])) {
                    $this->db->exec(
                        'UPDATE ' . Connection::table('marketplace_stock_reservation')
                        . ' SET `status` = ' . Connection::quote('consumed')
                        . ', `id_marketplace_vendor_order` = ' . $vendorOrderId
                        . ' WHERE `id_marketplace_stock_reservation` = ' . (int) $reservationIdByOfferId[$line->offerId]
                    );
                }
            }
            $this->db->exec(
                'INSERT INTO ' . Connection::table('marketplace_commission')
                . ' (`id_marketplace_vendor`, `id_marketplace_vendor_order`, `gross_cents`, `commission_percent`, `commission_cents`, `net_cents`, `date_add`) VALUES ('
                . (int) $vendorOrder->vendorId . ', ' . $vendorOrderId . ', ' . (int) $vendorOrder->productCents . ', '
                . Connection::quote($vendorOrder->commissionPercent) . ', ' . (int) $vendorOrder->commissionCents . ', '
                . (int) $vendorOrder->netCents . ', ' . $now . ')'
            );
            $this->addToSettlement($vendorOrder->vendorId, $vendorOrder->productCents, $vendorOrder->commissionCents, $vendorOrder->netCents);
        }

        return $parentId;
    }

    public function recent(int $limit = 20, ?int $vendorId = null): array
    {
        $sql = 'SELECT o.`reference` AS parent_reference, o.`date_add`, o.`optimization_status`, o.`status` AS order_status, o.`postcode`, o.`grand_cents` AS parent_grand,
                       o.`payment_method`, o.`payment_status`, o.`id_customer`, cu.`firstname`, cu.`lastname`,
                       v.`reference`, v.`id_marketplace_vendor`, mv.`name` AS vendor_name, mv.`code`,
                       v.`product_cents`, v.`shipping_cents`, v.`grand_cents`, v.`id_marketplace_vendor_order`,
                       v.`id_order`, v.`shop_reference`,
                       COALESCE(c.`commission_cents`, 0) AS commission_cents, COALESCE(c.`net_cents`, 0) AS net_cents
                FROM ' . Connection::table('marketplace_vendor_order') . ' v
                INNER JOIN ' . Connection::table('marketplace_order') . ' o ON o.`id_marketplace_order` = v.`id_marketplace_order`
                INNER JOIN ' . Connection::table('marketplace_vendor') . ' mv ON mv.`id_marketplace_vendor` = v.`id_marketplace_vendor`
                LEFT JOIN `' . _DB_PREFIX_ . 'customer` cu ON cu.`id_customer` = o.`id_customer`
                LEFT JOIN ' . Connection::table('marketplace_commission') . ' c ON c.`id_marketplace_vendor_order` = v.`id_marketplace_vendor_order`';
        if ($vendorId !== null) {
            $sql .= ' WHERE v.`id_marketplace_vendor` = ' . (int) $vendorId;
        }
        $sql .= ' ORDER BY v.`id_marketplace_vendor_order` DESC LIMIT ' . (int) $limit;
        $orders = $this->db->all($sql);
        if ($orders === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id_marketplace_vendor_order'], $orders);
        $lineRows = $this->db->all(
            'SELECT * FROM ' . Connection::table('marketplace_vendor_order_line')
            . ' WHERE `id_marketplace_vendor_order` IN (' . implode(',', $ids) . ')'
        );
        $lines = [];
        foreach ($lineRows as $line) {
            $lines[(int) $line['id_marketplace_vendor_order']][] = $line;
        }
        foreach ($orders as &$order) {
            $order['lines'] = $lines[(int) $order['id_marketplace_vendor_order']] ?? [];
        }

        return $orders;
    }

    public function findParent(string $reference): ?array
    {
        $parent = $this->db->one(
            'SELECT * FROM ' . Connection::table('marketplace_order')
            . ' WHERE `reference` = ' . Connection::quote($reference)
        );
        if ($parent === null) {
            return null;
        }
        $parent['vendors'] = array_values(array_filter(
            $this->db->all(
                'SELECT v.*, mv.`name` AS vendor_name, mv.`code`
                 FROM ' . Connection::table('marketplace_vendor_order') . ' v
                 INNER JOIN ' . Connection::table('marketplace_vendor') . ' mv ON mv.`id_marketplace_vendor` = v.`id_marketplace_vendor`
                 WHERE v.`id_marketplace_order` = ' . (int) $parent['id_marketplace_order']
            ),
            static fn (array $row): bool => true
        ));
        foreach ($parent['vendors'] as &$vendor) {
            $vendor['lines'] = $this->db->all(
                'SELECT * FROM ' . Connection::table('marketplace_vendor_order_line')
                . ' WHERE `id_marketplace_vendor_order` = ' . (int) $vendor['id_marketplace_vendor_order']
            );
            $vendor['commission'] = $this->db->one(
                'SELECT * FROM ' . Connection::table('marketplace_commission')
                . ' WHERE `id_marketplace_vendor_order` = ' . (int) $vendor['id_marketplace_vendor_order']
            );
        }

        return $parent;
    }

    public function attachCheckout(string $reference, int $customerId, string $method, string $paymentStatus, string $status): void
    {
        $this->db->exec(
            'UPDATE ' . Connection::table('marketplace_order')
            . ' SET `id_customer` = ' . (int) $customerId
            . ', `payment_method` = ' . Connection::quote($method)
            . ', `payment_status` = ' . Connection::quote($paymentStatus)
            . ', `status` = ' . Connection::quote($status)
            . ' WHERE `reference` = ' . Connection::quote($reference)
        );
        $this->db->exec(
            'UPDATE ' . Connection::table('marketplace_vendor_order')
            . ' SET `status` = ' . Connection::quote($status)
            . ' WHERE `parent_reference` = ' . Connection::quote($reference)
        );
    }

    public function attachShopOrder(int $vendorOrderId, int $orderId, string $shopReference): void
    {
        $this->db->exec(
            'UPDATE ' . Connection::table('marketplace_vendor_order')
            . ' SET `id_order` = ' . (int) $orderId
            . ', `shop_reference` = ' . Connection::quote($shopReference)
            . ' WHERE `id_marketplace_vendor_order` = ' . (int) $vendorOrderId
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCustomer(int $customerId): array
    {
        return $this->db->all(
            'SELECT `reference`, `date_add`, `grand_cents`, `product_cents`, `shipping_cents`, `payment_method`, `payment_status`, `status`, `postcode`
             FROM ' . Connection::table('marketplace_order')
             . ' WHERE `id_customer` = ' . (int) $customerId
             . ' ORDER BY `id_marketplace_order` DESC'
        );
    }

    private function addToSettlement(int $vendorId, int $gross, int $commission, int $net): void
    {
        $start = date('Y-m-01');
        $end = date('Y-m-t');
        $existing = $this->db->one(
            'SELECT `id_marketplace_settlement`, `gross_cents`, `commission_cents`, `net_cents`
             FROM ' . Connection::table('marketplace_settlement')
             . ' WHERE `id_marketplace_vendor` = ' . (int) $vendorId
             . ' AND `status` = ' . Connection::quote('open')
             . ' AND `period_start` = ' . Connection::quote($start)
        );
        if ($existing) {
            $this->db->exec(
                'UPDATE ' . Connection::table('marketplace_settlement')
                . ' SET `gross_cents` = ' . ((int) $existing['gross_cents'] + $gross)
                . ', `commission_cents` = ' . ((int) $existing['commission_cents'] + $commission)
                . ', `net_cents` = ' . ((int) $existing['net_cents'] + $net)
                . ' WHERE `id_marketplace_settlement` = ' . (int) $existing['id_marketplace_settlement']
            );

            return;
        }

        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_settlement')
            . ' (`id_marketplace_vendor`, `period_start`, `period_end`, `gross_cents`, `commission_cents`, `net_cents`, `status`, `date_add`) VALUES ('
            . (int) $vendorId . ', ' . Connection::quote($start) . ', ' . Connection::quote($end) . ', '
            . (int) $gross . ', ' . (int) $commission . ', ' . (int) $net . ', ' . Connection::quote('open') . ', '
            . Connection::quote(date('Y-m-d H:i:s')) . ')'
        );
    }
}
