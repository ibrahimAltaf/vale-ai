<?php

declare(strict_types=1);

namespace Marketplace\Optimizer\Dto;

use Marketplace\Core\Domain\OptimizationStatus;

final class OptimizationResult
{
    /**
     * @param list<AllocationLine> $lines
     * @param list<VendorAllocation> $vendors
     */
    public function __construct(
        public readonly OptimizationStatus $status,
        public readonly int $productSubtotalCents,
        public readonly int $shippingTotalCents,
        public readonly int $grandTotalCents,
        public readonly array $lines,
        public readonly array $vendors,
        public readonly int $combinationsEvaluated,
        public readonly int $searchSpace,
        public readonly string $explanation,
    ) {
    }

    public function quantityFor(string $vendorCode, int $productId): int
    {
        $quantity = 0;
        foreach ($this->lines as $line) {
            if ($line->vendorCode === $vendorCode && $line->productId === $productId) {
                $quantity += $line->quantity;
            }
        }

        return $quantity;
    }
}
