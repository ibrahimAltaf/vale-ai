<?php

declare(strict_types=1);

namespace Marketplace\Commission\Dto;

final class CommissionBreakdown
{
    public function __construct(
        public readonly int $grossCents,
        public readonly string $percent,
        public readonly int $commissionCents,
        public readonly int $netCents,
    ) {
    }
}
