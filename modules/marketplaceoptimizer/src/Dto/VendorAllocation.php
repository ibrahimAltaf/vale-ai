<?php

declare(strict_types=1);

namespace Marketplace\Optimizer\Dto;

final class VendorAllocation
{
    /**
     * @param list<AllocationLine> $lines
     */
    public function __construct(
        public readonly int $vendorId,
        public readonly string $vendorCode,
        public readonly string $vendorName,
        public readonly int $productSubtotalCents,
        public readonly int $shippingCents,
        public readonly int $weightGrams,
        public readonly bool $freeShippingApplied,
        public readonly string $shippingReason,
        public readonly array $lines,
    ) {
    }

    public function grandTotalCents(): int
    {
        return $this->productSubtotalCents + $this->shippingCents;
    }
}
