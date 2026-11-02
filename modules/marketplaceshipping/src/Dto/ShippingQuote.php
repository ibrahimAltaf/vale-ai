<?php

declare(strict_types=1);

namespace Marketplace\Shipping\Dto;

final class ShippingQuote
{
    public function __construct(
        public readonly bool $eligible,
        public readonly int $amountCents,
        public readonly bool $freeShippingApplied,
        public readonly string $reason,
    ) {
    }
}
