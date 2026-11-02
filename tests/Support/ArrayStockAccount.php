<?php

declare(strict_types=1);

namespace Marketplace\Tests\Support;

use Marketplace\Orders\Contract\StockAccount;
use Marketplace\Orders\Dto\StockReservation;
use Marketplace\Orders\Dto\StockSnapshot;

final class ArrayStockAccount implements StockAccount
{
    /** @var array<int, array{on: int, reserved: int}> */
    public array $stocks = [];

    /** @var list<StockReservation> */
    public array $reservations = [];

    private int $sequence = 1;

    public function setStock(int $offerId, int $onHand, int $reserved = 0): void
    {
        $this->stocks[$offerId] = ['on' => $onHand, 'reserved' => $reserved];
    }

    public function lock(int $offerId): StockSnapshot
    {
        $row = $this->stocks[$offerId] ?? ['on' => 0, 'reserved' => 0];

        return new StockSnapshot($offerId, $row['on'], $row['reserved']);
    }

    public function save(int $offerId, int $onHand, int $reserved): void
    {
        $this->stocks[$offerId] = ['on' => $onHand, 'reserved' => $reserved];
    }

    public function addReservation(int $offerId, int $quantity, \DateTimeImmutable $expiresAt): int
    {
        $id = $this->sequence++;
        $this->reservations[] = new StockReservation($id, $offerId, $quantity, 'active', $expiresAt);

        return $id;
    }

    public function expiredActiveReservations(\DateTimeImmutable $now): array
    {
        $expired = [];
        foreach ($this->reservations as $reservation) {
            if ($reservation->status === 'active' && $reservation->expiresAt <= $now) {
                $expired[] = $reservation;
            }
        }

        return $expired;
    }

    public function markReleased(int $reservationId): void
    {
        foreach ($this->reservations as $index => $reservation) {
            if ($reservation->id !== $reservationId) {
                continue;
            }
            $this->reservations[$index] = new StockReservation(
                $reservation->id,
                $reservation->offerId,
                $reservation->quantity,
                'released',
                $reservation->expiresAt,
            );

            return;
        }
    }
}
