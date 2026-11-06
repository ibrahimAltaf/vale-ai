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
