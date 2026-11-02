<?php

declare(strict_types=1);

namespace Marketplace\Orders\Dto;

use Marketplace\Optimizer\Dto\AllocationLine;

final class VendorOrderDraft
{
    /**
     * @param list<AllocationLine> $lines
     */
    public function __construct(
        public readonly string $reference,
        public readonly int $vendorId,
        public readonly string $vendorCode,
        public readonly string $vendorName,
        public readonly int $productCents,
        public readonly int $shippingCents,
        public readonly int $grandCents,
        public readonly string $commissionPercent,
        public readonly int $commissionCents,
        public readonly int $netCents,
        public readonly array $lines,
    ) {
    }
}
