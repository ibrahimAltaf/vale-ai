<?php

declare(strict_types=1);

namespace Marketplace\Tests\Platform;

use Marketplace\Core\Domain\OptimizationStatus;
use Marketplace\Optimizer\Dto\CartInput;
use Marketplace\Optimizer\Dto\CartLine;
use Marketplace\Optimizer\Dto\OfferOption;
use Marketplace\Optimizer\Service\CartOptimizer;
use Marketplace\Shipping\Dto\ShippingRule;
use Marketplace\Shipping\Service\ShippingCalculator;
use PHPUnit\Framework\TestCase;

final class OptimizerReferenceTest extends TestCase
{
    public function testOneLineSplitMatchesTheExhaustiveReference(): void
    {
        $shipping = new ShippingRule(1, 0, null, 0, []);
        $offers = [
            new OfferOption(1, 1, 'A', 'Alpine', 10, 500, 2, 0, $shipping),
            new OfferOption(2, 2, 'B', 'Harbor', 10, 300, 2, 0, new ShippingRule(2, 200, null, 0, [])),
        ];
        $cart = new CartInput([new CartLine(10, 'Sample', 2)], [10 => $offers], '10115');
        $started = hrtime(true);
        $result = (new CartOptimizer(new ShippingCalculator()))->optimize($cart);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        $reference = $this->cheapest($offers, 2);

        self::assertSame(OptimizationStatus::OptimalVerified, $result->status);
        self::assertSame($reference, $result->grandTotalCents);
        self::assertLessThan(5.0, $elapsed);
    }

    public function testPalletFreightIsNotZeroedByParcelFreeShipping(): void
    {
        $parcel = new ShippingRule(1, 0, 100000, 0, []);
        $pallet = new ShippingRule(1, 0, 100000, 0, [], 6500);
        $offers = [
            new OfferOption(1, 1, 'A', 'Parcel', 10, 100000, 1, 0, $parcel),
            new OfferOption(2, 2, 'B', 'Freight', 10, 100000, 1, 0, $pallet, 0, true),
        ];
        $cart = new CartInput([new CartLine(10, 'Bulk', 1)], [10 => $offers], '10115');
        $result = (new CartOptimizer(new ShippingCalculator()))->optimize($cart);

        self::assertSame(OptimizationStatus::OptimalVerified, $result->status);
        self::assertSame(100000, $result->grandTotalCents);
        self::assertSame(0, $result->shippingTotalCents);
    }

    public function testMandatoryPalletKeepsItsOwnFreightPrice(): void
    {
        $rule = new ShippingRule(1, 0, 100000, 0, [], 6500);
        $cart = new CartInput(
            [new CartLine(10, 'Bulk', 1)],
            [10 => [new OfferOption(1, 1, 'A', 'Freight', 10, 100000, 1, 0, $rule, 0, true)]],
            '10115',
        );
        $result = (new CartOptimizer(new ShippingCalculator()))->optimize($cart);
        $quote = (new ShippingCalculator())->quote($rule, 100000, 0, '10115', 0, true);

        self::assertSame(6500, $quote->amountCents);
        self::assertFalse($quote->freeShippingApplied);
        self::assertSame(OptimizationStatus::OptimalVerified, $result->status);
        self::assertSame(106500, $result->grandTotalCents);
    }

    public function testDisabledMinimumDoesNotBlockAndEnabledMinimumExcludesTheVendor(): void
    {
        $belowMinimum = new ShippingRule(1, 0, null, 0, [], minOrderEnabled: true, minOrderCents: 5000);
        $open = new ShippingRule(2, 0, null, 0, []);
        $cart = new CartInput(
            [new CartLine(10, 'Sample', 1)],
            [10 => [
                new OfferOption(1, 1, 'A', 'Minimum', 10, 1000, 5, 0, $belowMinimum),
                new OfferOption(2, 2, 'B', 'Open', 10, 2000, 5, 0, $open),
            ]],
            '10115',
        );
        $result = (new CartOptimizer(new ShippingCalculator()))->optimize($cart);
        self::assertSame(OptimizationStatus::OptimalVerified, $result->status);
        self::assertSame(2000, $result->grandTotalCents);

        $blocked = new CartInput(
            [new CartLine(10, 'Sample', 1)],
            [10 => [new OfferOption(1, 1, 'A', 'Minimum', 10, 1000, 5, 0, $belowMinimum)]],
            '10115',
        );
        $none = (new CartOptimizer(new ShippingCalculator()))->optimize($blocked);
        self::assertSame(OptimizationStatus::Infeasible, $none->status);

        $disabled = new ShippingRule(1, 0, null, 0, [], minOrderEnabled: false, minOrderCents: 5000);
        $allowed = (new CartOptimizer(new ShippingCalculator()))->optimize(new CartInput(
            [new CartLine(10, 'Sample', 1)],
            [10 => [new OfferOption(1, 1, 'A', 'Minimum', 10, 1000, 5, 0, $disabled)]],
            '10115',
        ));
        self::assertSame(1000, $allowed->grandTotalCents);
    }

    public function testFallbackIsNotDescribedAsOptimal(): void
    {
        $shipping = new ShippingRule(1, 0, null, 0, []);
        $cart = new CartInput(
            [new CartLine(10, 'Sample', 1)],
            [10 => [new OfferOption(1, 1, 'A', 'Alpine', 10, 500, 1, 0, $shipping)]],
            '10115',
        );
        $result = (new CartOptimizer(new ShippingCalculator(), 0))->optimize($cart);
        self::assertSame(OptimizationStatus::FallbackNotProven, $result->status);
        self::assertStringContainsString('not a proven optimum', $result->explanation);
    }

    /**
     * @param list<OfferOption> $offers
     */
    private function cheapest(array $offers, int $quantity): int
    {
        $best = null;
        $this->walk($offers, $quantity, 0, [], $best);

        return $best ?? 0;
    }

    /**
     * @param list<OfferOption> $offers
     * @param array<int, int> $taken
     */
    private function walk(array $offers, int $remaining, int $index, array $taken, ?int &$best): void
    {
        if ($remaining === 0) {
            $cost = $this->cost($offers, $taken);
            if ($cost !== null && ($best === null || $cost < $best)) {
                $best = $cost;
            }

            return;
        }
        if ($index >= count($offers)) {
            return;
        }
        $offer = $offers[$index];
        $max = min($remaining, $offer->stock);
        for ($qty = 0; $qty <= $max; $qty++) {
            $next = $taken;
            if ($qty > 0) {
                $next[$offer->offerId] = $qty;
            }
            $this->walk($offers, $remaining - $qty, $index + 1, $next, $best);
        }
    }

    /**
     * @param list<OfferOption> $offers
     * @param array<int, int> $taken
     */
    private function cost(array $offers, array $taken): ?int
    {
        $byVendor = [];
        foreach ($offers as $offer) {
            $qty = $taken[$offer->offerId] ?? 0;
            if ($qty < 1) {
                continue;
            }
            $byVendor[$offer->vendorId]['product'] = ($byVendor[$offer->vendorId]['product'] ?? 0) + ($offer->unitPriceCents * $qty);
            $byVendor[$offer->vendorId]['weight'] = ($byVendor[$offer->vendorId]['weight'] ?? 0) + ($offer->weightGrams * $qty);
            $byVendor[$offer->vendorId]['rule'] = $offer->shipping;
        }
        $calculator = new ShippingCalculator();
        $total = 0;
        foreach ($byVendor as $vendor) {
            $quote = $calculator->quote($vendor['rule'], $vendor['product'], $vendor['weight'], '10115');
            if (!$quote->eligible) {
                return null;
            }
            $total += $vendor['product'] + $quote->amountCents;
        }

        return $total;
    }
}
