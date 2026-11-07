<?php

declare(strict_types=1);

namespace Marketplace\Shipping\Service;

use Marketplace\Shipping\Dto\ShippingQuote;
use Marketplace\Shipping\Dto\ShippingRule;

/**
 * Vendor shipping: base amount, optional per-kilogram surcharge, postcode prefixes,
 * and a free-shipping threshold applied to that vendor's product subtotal.
 */
final class ShippingCalculator
{
    public function quote(
        ShippingRule $rule,
        int $productSubtotalCents,
        int $weightGrams,
        string $postcode,
        int $volumeCm3 = 0,
        bool $palletOnly = false,
    ): ShippingQuote {
        if ($productSubtotalCents < 0 || $weightGrams < 0 || $volumeCm3 < 0) {
            throw new \InvalidArgumentException('Shipping inputs cannot be negative.');
        }

        if (!$this->postcodeAllowed($rule->postcodePrefixes, $postcode)) {
            return new ShippingQuote(false, 0, false, 'Postcode is outside this vendor shipping area.');
        }

        if ($this->usesPallet($rule, $weightGrams, $volumeCm3, $palletOnly)) {
            return $this->palletQuote($rule, $productSubtotalCents, $weightGrams, $volumeCm3);
        }

        $weightCharge = intdiv($weightGrams * $rule->surchargeCentsPerKg, 1000);
        $free = $rule->freeThresholdCents !== null && $productSubtotalCents >= $rule->freeThresholdCents;
        if ($free) {
            return new ShippingQuote(true, 0, true, 'Free-shipping threshold reached.');
        }

        return new ShippingQuote(
            true,
            $rule->baseCents + $weightCharge,
            false,
            $weightCharge > 0 ? 'Base shipping plus weight surcharge.' : 'Base shipping.',
        );
    }

    /**
     * @param list<string> $prefixes
     */
    public function postcodeAllowed(array $prefixes, string $postcode): bool
    {
        if ($prefixes === []) {
            return true;
        }

        $normalized = $this->normalize($postcode);
        if ($normalized === '') {
            return false;
        }

        foreach ($prefixes as $prefix) {
            $prefix = $this->normalize($prefix);
            if ($prefix !== '' && str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function usesPallet(ShippingRule $rule, int $weightGrams, int $volumeCm3, bool $palletOnly): bool
    {
        if ($palletOnly) {
            return true;
        }
        if ($rule->parcelMaxWeightGrams !== null && $weightGrams > $rule->parcelMaxWeightGrams) {
            return true;
        }

        return $rule->parcelMaxVolumeCm3 !== null && $volumeCm3 > $rule->parcelMaxVolumeCm3;
    }

    private function palletQuote(ShippingRule $rule, int $productSubtotalCents, int $weightGrams, int $volumeCm3): ShippingQuote
    {
        $pallets = 1;
        if ($rule->palletCapacityWeightGrams !== null && $rule->palletCapacityWeightGrams > 0 && $weightGrams > 0) {
            $pallets = max($pallets, (int) ceil($weightGrams / $rule->palletCapacityWeightGrams));
        }
        if ($rule->palletCapacityVolumeCm3 !== null && $rule->palletCapacityVolumeCm3 > 0 && $volumeCm3 > 0) {
            $pallets = max($pallets, (int) ceil($volumeCm3 / $rule->palletCapacityVolumeCm3));
        }
        $free = $rule->palletFreeThresholdCents !== null && $productSubtotalCents >= $rule->palletFreeThresholdCents;

        return new ShippingQuote(
            true,
            $free ? 0 : $rule->palletBaseCents * $pallets,
            $free,
            $free ? 'Pallet free-shipping threshold reached.' : 'Pallet freight.',
        );
    }

    private function normalize(string $value): string
    {
        $value = strtoupper(preg_replace('/\s+/', '', $value) ?? '');

        return $value;
    }
}
