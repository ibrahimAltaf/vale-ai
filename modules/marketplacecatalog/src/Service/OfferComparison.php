<?php

declare(strict_types=1);

namespace Marketplace\Catalog\Service;

use Marketplace\Shipping\Dto\ShippingRule;
use Marketplace\Shipping\Service\ShippingCalculator;

final class ComparedOffer
{
    public function __construct(
        public readonly int $offerId,
        public readonly int $vendorId,
        public readonly string $vendorCode,
        public readonly string $vendorName,
        public readonly int $listCents,
        public readonly int $netCents,
        public readonly int $stock,
        public readonly bool $shippingEligible,
        public readonly int $shippingCents,
        public readonly ?int $deliveredCents,
        public readonly bool $cheapestUnit,
        public readonly bool $cheapestDelivered,
        public readonly string $shippingReason,
    ) {
    }
}

final class OfferComparisonResult
{
    /**
     * @param list<ComparedOffer> $offers
     */
    public function __construct(
        public readonly array $offers,
        public readonly ?int $cheapestUnitOfferId,
        public readonly ?int $cheapestDeliveredOfferId,
    ) {
    }
}

/**
 * Separates the cheapest product price from the cheapest delivered cost for quantity 1.
 */
final class OfferComparison
{
    public function __construct(private readonly ShippingCalculator $shipping)
    {
    }

    /**
     * @param list<array{
     *   offer_id: int,
     *   vendor_id: int,
     *   vendor_code: string,
     *   vendor_name: string,
     *   list_cents: int,
     *   net_cents: int,
     *   stock: int,
     *   weight_grams: int,
     *   shipping: ShippingRule
     * }> $offers
     */
    public function compare(array $offers, string $postcode): OfferComparisonResult
    {
        $rows = [];
        foreach ($offers as $offer) {
            $quote = $this->shipping->quote($offer['shipping'], $offer['net_cents'], $offer['weight_grams'], $postcode);
            $delivered = $quote->eligible && $offer['stock'] > 0 ? $offer['net_cents'] + $quote->amountCents : null;
            $rows[] = [
                'offer' => $offer,
                'eligible' => $quote->eligible,
                'shipping' => $quote->amountCents,
                'delivered' => $delivered,
                'reason' => $quote->reason,
            ];
        }

        $cheapestUnit = null;
        $cheapestDelivered = null;
        foreach ($rows as $row) {
            if ($row['offer']['stock'] < 1) {
                continue;
            }
            if ($cheapestUnit === null || $row['offer']['net_cents'] < $cheapestUnit['offer']['net_cents'] || ($row['offer']['net_cents'] === $cheapestUnit['offer']['net_cents'] && $row['offer']['vendor_id'] < $cheapestUnit['offer']['vendor_id'])) {
                $cheapestUnit = $row;
            }
            if ($row['delivered'] === null) {
                continue;
            }
            if ($cheapestDelivered === null || $row['delivered'] < $cheapestDelivered['delivered'] || ($row['delivered'] === $cheapestDelivered['delivered'] && $row['offer']['vendor_id'] < $cheapestDelivered['offer']['vendor_id'])) {
                $cheapestDelivered = $row;
            }
        }

        $compared = [];
        foreach ($rows as $row) {
            $offer = $row['offer'];
            $compared[] = new ComparedOffer(
                $offer['offer_id'],
                $offer['vendor_id'],
                $offer['vendor_code'],
                $offer['vendor_name'],
                $offer['list_cents'],
                $offer['net_cents'],
                $offer['stock'],
                $row['eligible'],
                $row['shipping'],
                $row['delivered'],
                $cheapestUnit !== null && $offer['offer_id'] === $cheapestUnit['offer']['offer_id'],
                $cheapestDelivered !== null && $offer['offer_id'] === $cheapestDelivered['offer']['offer_id'],
                $row['reason'],
            );
        }

        return new OfferComparisonResult(
            $compared,
            $cheapestUnit['offer']['offer_id'] ?? null,
            $cheapestDelivered['offer']['offer_id'] ?? null,
        );
    }
}
