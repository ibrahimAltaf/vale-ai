<?php

declare(strict_types=1);

namespace Marketplace\Tests\Collections;

use Marketplace\Collections\Dto\CollectionWindow;
use Marketplace\Collections\Service\CollectionSchedule;
use Marketplace\Core\Seed\DemoCatalog;
use PHPUnit\Framework\TestCase;

final class CollectionScheduleTest extends TestCase
{
    private CollectionSchedule $schedule;

    protected function setUp(): void
    {
        $this->schedule = new CollectionSchedule();
    }

    public function testDemoCollectionsOnReviewDay(): void
    {
        $now = new \DateTimeImmutable('2026-11-02 12:00:00');
        $statuses = [];
        foreach (DemoCatalog::collections() as $collection) {
            $window = new CollectionWindow(
                $collection['name'],
                $collection['slug'],
                true,
                $collection['starts_at'] ? new \DateTimeImmutable($collection['starts_at']) : null,
                $collection['ends_at'] ? new \DateTimeImmutable($collection['ends_at']) : null,
            );
            $statuses[$collection['slug']] = $this->schedule->status($window, $now);
        }

        self::assertSame(CollectionSchedule::LIVE, $statuses['breakfast']);
        self::assertSame(CollectionSchedule::LIVE, $statuses['aperitivo']);
        self::assertSame(CollectionSchedule::SCHEDULED, $statuses['christmas']);
        self::assertSame(CollectionSchedule::SCHEDULED, $statuses['easter']);
    }

    public function testWindowBoundariesAreInclusive(): void
    {
        $window = new CollectionWindow(
            'Christmas',
            'christmas',
            true,
            new \DateTimeImmutable('2026-12-01 00:00:00'),
            new \DateTimeImmutable('2026-12-26 23:59:59'),
        );

        self::assertTrue($this->schedule->isLive($window, new \DateTimeImmutable('2026-12-01 00:00:00')));
        self::assertTrue($this->schedule->isLive($window, new \DateTimeImmutable('2026-12-26 23:59:59')));
        self::assertSame(CollectionSchedule::ENDED, $this->schedule->status($window, new \DateTimeImmutable('2026-12-27 00:00:00')));
    }

    public function testDisabledCollectionStaysDisabledInsideItsWindow(): void
    {
        $window = new CollectionWindow('Breakfast', 'breakfast', false, null, null);

        self::assertSame(CollectionSchedule::DISABLED, $this->schedule->status($window, new \DateTimeImmutable('2026-11-02 12:00:00')));
    }
}
