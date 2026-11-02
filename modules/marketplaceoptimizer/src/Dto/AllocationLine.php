<?php

declare(strict_types=1);

namespace Marketplace\Optimizer\Dto;

final class AllocationLine
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        public readonly int $offerId,
        public readonly int $vendorId,
        public readonly string $vendorCode,
        public readonly string $vendorName,
        public readonly int $quantity,
        public readonly int $unitPriceCents,
        public readonly int $lineTotalCents,
        public readonly int $weightGrams,
    ) {
    }
}
