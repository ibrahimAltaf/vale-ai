<?php

declare(strict_types=1);

namespace Marketplace\Tests\Orders;

use Marketplace\Orders\Exception\InsufficientStockException;
use Marketplace\Orders\Service\StockReservationService;
use Marketplace\Tests\Support\ArrayStockAccount;
use PHPUnit\Framework\TestCase;

final class StockReservationTest extends TestCase
{
    public function testReserveDecreasesAvailableStock(): void
    {
        $account = new ArrayStockAccount();
        $account->setStock(13, 2);
        $account->setStock(14, 4);
        $service = new StockReservationService();
        $now = new \DateTimeImmutable('2026-11-02 10:00:00');

        $service->reserve($account, [14 => 4, 13 => 2], $now->modify('+30 minutes'), $now);

        self::assertSame(0, $account->lock(13)->available());
        self::assertSame(0, $account->lock(14)->available());
        self::assertCount(2, $account->reservations);
    }

    public function testCannotReserveBeyondAvailableStock(): void
    {
        $account = new ArrayStockAccount();
        $account->setStock(13, 2);
        $service = new StockReservationService();
        $now = new \DateTimeImmutable('2026-11-02 10:00:00');

        $this->expectException(InsufficientStockException::class);
        $service->reserve($account, [13 => 3], $now->modify('+30 minutes'), $now);
    }

    public function testFailedSecondReserveDoesNotConsumeTheFirstOffer(): void
    {
        $account = new ArrayStockAccount();
        $account->setStock(13, 2);
        $account->setStock(14, 1);
        $service = new StockReservationService();
        $now = new \DateTimeImmutable('2026-11-02 10:00:00');

        try {
            $service->reserve($account, [13 => 2, 14 => 4], $now->modify('+30 minutes'), $now);
            self::fail('Expected insufficient stock.');
        } catch (InsufficientStockException $exception) {
            self::assertSame(14, $exception->offerId);
        }

        self::assertSame(2, $account->lock(13)->available());
        self::assertSame(0, $account->lock(13)->reserved);
    }

    public function testExpiredReservationIsReleasedBeforeANewReserve(): void
    {
        $account = new ArrayStockAccount();
        $account->setStock(13, 2, 2);
        $now = new \DateTimeImmutable('2026-11-02 10:00:00');
        $account->addReservation(13, 2, $now->modify('-1 minute'));
        $service = new StockReservationService();

        $service->reserve($account, [13 => 2], $now->modify('+30 minutes'), $now);

        self::assertSame(0, $account->lock(13)->available());
        self::assertSame(2, $account->lock(13)->reserved);
        self::assertSame('released', $account->reservations[0]->status);
        self::assertSame('active', $account->reservations[1]->status);
    }
}
