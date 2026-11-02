<?php

declare(strict_types=1);

namespace Marketplace\Orders\Service;

use Marketplace\Orders\Contract\StockAccount;
use Marketplace\Orders\Exception\InsufficientStockException;

/**
 * Reserves allocated units inside the caller's transaction.
 * Offer ids are locked in ascending order. Expired reservations are released first.
 */
final class StockReservationService
{
    /**
     * @param array<int, int> $quantityByOfferId
     * @return array<int, int> offer id => reservation id
     */
    public function reserve(StockAccount $account, array $quantityByOfferId, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): array
    {
        $this->releaseExpired($account, $now);

        $requests = [];
        foreach ($quantityByOfferId as $offerId => $quantity) {
            $offerId = (int) $offerId;
            $quantity = (int) $quantity;
            if ($quantity < 1) {
                throw new \InvalidArgumentException('Reservation quantity must be positive.');
            }
            $requests[$offerId] = ($requests[$offerId] ?? 0) + $quantity;
        }
        ksort($requests);

        /** @var array<int, array{0: \Marketplace\Orders\Dto\StockSnapshot, 1: int}> $locked */
        $locked = [];
        foreach ($requests as $offerId => $quantity) {
            $snapshot = $account->lock($offerId);
            if ($quantity > $snapshot->available()) {
                throw new InsufficientStockException($offerId, $quantity, $snapshot->available());
            }
            $locked[$offerId] = [$snapshot, $quantity];
        }

        $reservationIds = [];
        foreach ($locked as $offerId => [$snapshot, $quantity]) {
            $account->save($offerId, $snapshot->onHand, $snapshot->reserved + $quantity);
            $reservationIds[$offerId] = $account->addReservation($offerId, $quantity, $expiresAt);
        }

        return $reservationIds;
    }

    public function releaseExpired(StockAccount $account, \DateTimeImmutable $now): int
    {
        $released = 0;
        foreach ($account->expiredActiveReservations($now) as $reservation) {
            $snapshot = $account->lock($reservation->offerId);
            $account->save($reservation->offerId, $snapshot->onHand, max(0, $snapshot->reserved - $reservation->quantity));
            $account->markReleased($reservation->id);
            ++$released;
        }

        return $released;
    }
}
