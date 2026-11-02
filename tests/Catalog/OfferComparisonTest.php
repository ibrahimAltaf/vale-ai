<?php

declare(strict_types=1);

namespace Marketplace\Tests\Catalog;

use Marketplace\Catalog\Service\OfferComparison;
use Marketplace\Optimizer\Demo\ScenarioFactory;
use Marketplace\Shipping\Dto\ShippingRule;
use Marketplace\Shipping\Service\ShippingCalculator;
use PHPUnit\Framework\TestCase;

final class OfferComparisonTest extends TestCase
{
    public function testCoffeeCheapestUnitAndCheapestDeliveredAreReportedSeparately(): void
    {
        $comparison = new OfferComparison(new ShippingCalculator());
        $product = null;
        foreach (\Marketplace\Core\Seed\DemoCatalog::products() as $candidate) {
            if ($candidate['sku'] === 'COFFEE-1KG') {
                $product = $candidate;
            }
        }
        self::assertNotNull($product);

        $rows = [];
        foreach (ScenarioFactory::offersFor($product) as $offer) {
            $rows[] = [
                'offer_id' => $offer->offerId,
                'vendor_id' => $offer->vendorId,
                'vendor_code' => $offer->vendorCode,
                'vendor_name' => $offer->vendorName,
                'list_cents' => $offer->unitPriceCents,
                'net_cents' => $offer->unitPriceCents,
                'stock' => $offer->stock,
                'weight_grams' => $offer->weightGrams,
                'shipping' => $offer->shipping,
            ];
        }

        $result = $comparison->compare($rows, '10115');

        self::assertSame(2, $result->cheapestUnitOfferId);
        self::assertSame(2, $result->cheapestDeliveredOfferId);
        $harbor = null;
        foreach ($result->offers as $offer) {
            if ($offer->vendorCode === 'B') {
                $harbor = $offer;
            }
        }
        self::assertNotNull($harbor);
        self::assertSame(1120, $harbor->netCents);
        self::assertSame(300, $harbor->shippingCents);
        self::assertSame(1420, $harbor->deliveredCents);
        self::assertTrue($harbor->cheapestUnit);
        self::assertTrue($harbor->cheapestDelivered);
    }

    public function testCheapestDeliveredCanDifferFromCheapestUnit(): void
    {
        $comparison = new OfferComparison(new ShippingCalculator());
        $result = $comparison->compare([
            [
                'offer_id' => 1,
                'vendor_id' => 1,
                'vendor_code' => 'A',
                'vendor_name' => 'Alpine Roast',
                'list_cents' => 4800,
                'net_cents' => 4800,
                'stock' => 4,
                'weight_grams' => 500,
                'shipping' => new ShippingRule(1, 500, 5000, 0, []),
            ],
            [
                'offer_id' => 2,
                'vendor_id' => 2,
                'vendor_code' => 'B',
                'vendor_name' => 'Harbor Bean',
                'list_cents' => 5200,
                'net_cents' => 5200,
                'stock' => 4,
                'weight_grams' => 500,
                'shipping' => new ShippingRule(2, 300, 5000, 0, []),
            ],
        ], '10115');

        self::assertSame(1, $result->cheapestUnitOfferId);
        self::assertSame(2, $result->cheapestDeliveredOfferId);
        self::assertSame(5300, $result->offers[0]->deliveredCents);
        self::assertSame(5200, $result->offers[1]->deliveredCents);
    }
}
