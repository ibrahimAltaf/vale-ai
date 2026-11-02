<?php

declare(strict_types=1);

namespace Marketplace\Orders\Dto;

final class StockSnapshot
{
    public function __construct(
        public readonly int $offerId,
        public readonly int $onHand,
        public readonly int $reserved,
    ) {
    }

    public function available(): int
    {
        return max(0, $this->onHand - $this->reserved);
    }
}
