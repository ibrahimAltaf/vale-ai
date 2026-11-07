<?php

declare(strict_types=1);

namespace Marketplace\Optimizer\Dto;

use Marketplace\Shipping\Dto\ShippingRule;

final class OfferOption
{
    public function __construct(
        public readonly int $offerId,
        public readonly int $vendorId,
        public readonly string $vendorCode,
        public readonly string $vendorName,
        public readonly int $productId,
        public readonly int $unitPriceCents,
        public readonly int $stock,
        public readonly int $weightGrams,
        public readonly ShippingRule $shipping,
        public readonly int $volumeCm3 = 0,
        public readonly bool $palletOnly = false,
    ) {
    }
}
