<?php

declare(strict_types=1);

namespace Marketplace\Shipping\Dto;

final class ShippingRule
{
    /**
     * @param list<string> $postcodePrefixes Empty means every postcode is eligible.
     */
    public function __construct(
        public readonly int $vendorId,
        public readonly int $baseCents,
        public readonly ?int $freeThresholdCents,
        public readonly int $surchargeCentsPerKg,
        public readonly array $postcodePrefixes,
        public readonly int $palletBaseCents = 0,
        public readonly ?int $palletFreeThresholdCents = null,
        public readonly ?int $parcelMaxWeightGrams = null,
        public readonly ?int $parcelMaxVolumeCm3 = null,
        public readonly ?int $palletCapacityWeightGrams = null,
        public readonly ?int $palletCapacityVolumeCm3 = null,
        public readonly bool $minOrderEnabled = false,
        public readonly int $minOrderCents = 0,
    ) {
        if ($baseCents < 0 || $surchargeCentsPerKg < 0 || $palletBaseCents < 0 || $minOrderCents < 0) {
            throw new \InvalidArgumentException('Shipping amounts cannot be negative.');
        }
        if ($freeThresholdCents !== null && $freeThresholdCents < 0) {
            throw new \InvalidArgumentException('Free-shipping threshold cannot be negative.');
        }
        if ($palletFreeThresholdCents !== null && $palletFreeThresholdCents < 0) {
            throw new \InvalidArgumentException('Pallet free-shipping threshold cannot be negative.');
        }
    }
}
