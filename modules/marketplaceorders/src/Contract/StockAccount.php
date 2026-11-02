<?php

declare(strict_types=1);

namespace Marketplace\Orders\Contract;

use Marketplace\Orders\Dto\StockReservation;
use Marketplace\Orders\Dto\StockSnapshot;

/**
 * Storage port for reservations. The PrestaShop adapter locks rows with SELECT ... FOR UPDATE.
 */
interface StockAccount
{
    public function lock(int $offerId): StockSnapshot;

    public function save(int $offerId, int $onHand, int $reserved): void;

    public function addReservation(int $offerId, int $quantity, \DateTimeImmutable $expiresAt): int;

    /**
     * @return list<StockReservation>
     */
    public function expiredActiveReservations(\DateTimeImmutable $now): array;

    public function markReleased(int $reservationId): void;
}
