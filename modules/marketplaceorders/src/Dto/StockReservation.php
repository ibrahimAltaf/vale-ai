<?php

declare(strict_types=1);

namespace Marketplace\Orders\Dto;

final class StockReservation
{
    public function __construct(
        public readonly int $id,
        public readonly int $offerId,
        public readonly int $quantity,
        public readonly string $status,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
