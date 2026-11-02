<?php

declare(strict_types=1);

namespace Marketplace\Core\Repository;

use Marketplace\Core\Database\Connection;

final class DashboardRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function adminSummary(): array
    {
        return [
            'orders' => (int) $this->db->value('SELECT COUNT(*) FROM ' . Connection::table('marketplace_order')),
            'gmv_cents' => (int) $this->db->value('SELECT COALESCE(SUM(`product_cents`), 0) FROM ' . Connection::table('marketplace_order')),
            'shipping_cents' => (int) $this->db->value('SELECT COALESCE(SUM(`shipping_cents`), 0) FROM ' . Connection::table('marketplace_order')),
            'checkout_cents' => (int) $this->db->value('SELECT COALESCE(SUM(`grand_cents`), 0) FROM ' . Connection::table('marketplace_order')),
            'commission_cents' => (int) $this->db->value('SELECT COALESCE(SUM(`commission_cents`), 0) FROM ' . Connection::table('marketplace_commission')),
            'vendor_net_cents' => (int) $this->db->value('SELECT COALESCE(SUM(`net_cents`), 0) FROM ' . Connection::table('marketplace_commission')),
            'vendors' => (int) $this->db->value('SELECT COUNT(*) FROM ' . Connection::table('marketplace_vendor')),
            'offers' => (int) $this->db->value('SELECT COUNT(*) FROM ' . Connection::table('marketplace_vendor_offer') . ' WHERE `active` = 1'),
            'products' => (int) $this->db->value('SELECT COUNT(DISTINCT `id_product`) FROM ' . Connection::table('marketplace_vendor_offer') . ' WHERE `active` = 1'),
        ];
    }

    public function vendorSummary(int $vendorId): array
    {
        $where = ' WHERE `id_marketplace_vendor` = ' . (int) $vendorId;

        return [
            'orders' => (int) $this->db->value('SELECT COUNT(*) FROM ' . Connection::table('marketplace_vendor_order') . $where),
            'gmv_cents' => (int) $this->db->value('SELECT COALESCE(SUM(`product_cents`), 0) FROM ' . Connection::table('marketplace_vendor_order') . $where),
            'shipping_cents' => (int) $this->db->value('SELECT COALESCE(SUM(`shipping_cents`), 0) FROM ' . Connection::table('marketplace_vendor_order') . $where),
            'commission_cents' => (int) $this->db->value('SELECT COALESCE(SUM(`commission_cents`), 0) FROM ' . Connection::table('marketplace_commission') . $where),
            'net_cents' => (int) $this->db->value('SELECT COALESCE(SUM(`net_cents`), 0) FROM ' . Connection::table('marketplace_commission') . $where),
            'offers' => (int) $this->db->value('SELECT COUNT(*) FROM ' . Connection::table('marketplace_vendor_offer') . $where . ' AND `active` = 1'),
        ];
    }
}
