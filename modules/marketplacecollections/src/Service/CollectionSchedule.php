<?php

declare(strict_types=1);

namespace Marketplace\Collections\Service;

use Marketplace\Collections\Dto\CollectionWindow;

final class CollectionSchedule
{
    public const LIVE = 'LIVE';
    public const SCHEDULED = 'SCHEDULED';
    public const ENDED = 'ENDED';
    public const DISABLED = 'DISABLED';

    public function status(CollectionWindow $window, \DateTimeImmutable $now): string
    {
        if (!$window->enabled) {
            return self::DISABLED;
        }
        if ($window->startsAt instanceof \DateTimeImmutable && $now < $window->startsAt) {
            return self::SCHEDULED;
        }
        if ($window->endsAt instanceof \DateTimeImmutable && $now > $window->endsAt) {
            return self::ENDED;
        }

        return self::LIVE;
    }

    public function isLive(CollectionWindow $window, \DateTimeImmutable $now): bool
    {
        return $this->status($window, $now) === self::LIVE;
    }
}
