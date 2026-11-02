<?php

declare(strict_types=1);

namespace Marketplace\Orders\Dto;

final class SplitOrderPlan
{
    /**
     * @param list<VendorOrderDraft> $vendorOrders
     */
    public function __construct(
        public readonly string $parentReference,
        public readonly string $postcode,
        public readonly string $optimizationStatus,
        public readonly int $productCents,
        public readonly int $shippingCents,
        public readonly int $grandCents,
        public readonly array $vendorOrders,
    ) {
    }
}
