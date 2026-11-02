<?php

declare(strict_types=1);

namespace Marketplace\Orders\Adapter;

use Marketplace\Core\Database\Connection;
use Marketplace\Orders\Contract\StockAccount;
use Marketplace\Orders\Dto\StockReservation;
use Marketplace\Orders\Dto\StockSnapshot;

/**
 * Row locks use SELECT ... FOR UPDATE and must run inside Connection::transaction().
 */
final class PrestaShopStockAccount implements StockAccount
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function lock(int $offerId): StockSnapshot
    {
        // Db::getRow() appends LIMIT 1, which is invalid after FOR UPDATE. executeS does not.
        $rows = $this->db->all(
            'SELECT `quantity`, `reserved_quantity` FROM ' . Connection::table('marketplace_vendor_stock')
            . ' WHERE `id_marketplace_vendor_offer` = ' . (int) $offerId . ' FOR UPDATE'
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return new StockSnapshot($offerId, 0, 0);
        }

        return new StockSnapshot($offerId, (int) $row['quantity'], (int) $row['reserved_quantity']);
    }

    public function save(int $offerId, int $onHand, int $reserved): void
    {
        $this->db->exec(
            'UPDATE ' . Connection::table('marketplace_vendor_stock')
            . ' SET `quantity` = ' . (int) $onHand
            . ', `reserved_quantity` = ' . (int) $reserved
            . ', `date_upd` = ' . Connection::quote(date('Y-m-d H:i:s'))
            . ' WHERE `id_marketplace_vendor_offer` = ' . (int) $offerId
        );
    }

    public function addReservation(int $offerId, int $quantity, \DateTimeImmutable $expiresAt): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_stock_reservation')
            . ' (`id_marketplace_vendor_offer`, `quantity`, `status`, `expires_at`, `date_add`) VALUES ('
            . (int) $offerId . ', ' . (int) $quantity . ', ' . Connection::quote('active') . ', '
            . Connection::quote($expiresAt->format('Y-m-d H:i:s')) . ', ' . Connection::quote($now) . ')'
        );

        return $this->db->lastId();
    }

    public function expiredActiveReservations(\DateTimeImmutable $now): array
    {
        $rows = $this->db->all(
            'SELECT * FROM ' . Connection::table('marketplace_stock_reservation')
            . ' WHERE `status` = ' . Connection::quote('active')
            . ' AND `expires_at` <= ' . Connection::quote($now->format('Y-m-d H:i:s'))
            . ' FOR UPDATE'
        );
        $reservations = [];
        foreach ($rows as $row) {
            $reservations[] = new StockReservation(
                (int) $row['id_marketplace_stock_reservation'],
                (int) $row['id_marketplace_vendor_offer'],
                (int) $row['quantity'],
                (string) $row['status'],
                new \DateTimeImmutable((string) $row['expires_at']),
            );
        }

        return $reservations;
    }

    public function markReleased(int $reservationId): void
    {
        $this->db->exec(
            'UPDATE ' . Connection::table('marketplace_stock_reservation')
            . ' SET `status` = ' . Connection::quote('released')
            . ' WHERE `id_marketplace_stock_reservation` = ' . (int) $reservationId
        );
    }
}
