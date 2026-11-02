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
    ) {
        if ($baseCents < 0 || $surchargeCentsPerKg < 0) {
            throw new \InvalidArgumentException('Shipping amounts cannot be negative.');
        }
        if ($freeThresholdCents !== null && $freeThresholdCents < 0) {
            throw new \InvalidArgumentException('Free-shipping threshold cannot be negative.');
        }
    }
}
