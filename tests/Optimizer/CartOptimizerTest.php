<?php

declare(strict_types=1);

namespace Marketplace\Tests\Optimizer;

use Marketplace\Core\Domain\OptimizationStatus;
use Marketplace\Optimizer\Demo\ScenarioFactory;
use Marketplace\Optimizer\Dto\CartInput;
use Marketplace\Optimizer\Dto\CartLine;
use Marketplace\Optimizer\Dto\OfferOption;
use Marketplace\Optimizer\Service\CartOptimizer;
use Marketplace\Shipping\Dto\ShippingRule;
use Marketplace\Shipping\Service\ShippingCalculator;
use PHPUnit\Framework\TestCase;

final class CartOptimizerTest extends TestCase
{
    private CartOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->optimizer = new CartOptimizer(new ShippingCalculator());
    }

    public function testQuantityMustSplitWhenNoVendorCanFulfillAlone(): void
    {
        $result = $this->optimizer->optimize(ScenarioFactory::scenario('oats-must-split'));

        self::assertSame(OptimizationStatus::OptimalVerified, $result->status);
        self::assertSame(2, $result->quantityFor('A', 5));
        self::assertSame(4, $result->quantityFor('B', 5));
        self::assertSame(0, $result->quantityFor('C', 5));
        self::assertSame(5000, $result->productSubtotalCents);
        self::assertSame(800, $result->shippingTotalCents);
        self::assertSame(5800, $result->grandTotalCents);
        self::assertGreaterThan(0, $result->combinationsEvaluated);
        self::assertSame($result->searchSpace, $result->combinationsEvaluated);

        foreach (ScenarioFactory::scenario('oats-must-split')->offersByProductId[5] as $offer) {
            self::assertLessThan(6, $offer->stock);
        }
    }

    public function testCheapestUnitsAreNotTheCheapestDeliveredCart(): void
    {
        $cart = ScenarioFactory::scenario('breakfast-shipping');
        $result = $this->optimizer->optimize($cart);
        $baseline = $this->optimizer->unitPriceBaseline($cart);

        self::assertSame(OptimizationStatus::OptimalVerified, $result->status);
        self::assertSame(1, $result->quantityFor('A', 2));
        self::assertSame(1, $result->quantityFor('A', 3));
        self::assertSame(0, $result->quantityFor('B', 2));
        self::assertSame(0, $result->quantityFor('C', 3));
        self::assertSame(5200, $result->productSubtotalCents);
        self::assertSame(0, $result->shippingTotalCents);
        self::assertSame(5200, $result->grandTotalCents);
        self::assertTrue($result->vendors[0]->freeShippingApplied);

        self::assertSame(OptimizationStatus::UnitPriceBaseline, $baseline->status);
        self::assertSame(1, $baseline->quantityFor('B', 2));
        self::assertSame(1, $baseline->quantityFor('C', 3));
        self::assertSame(4500, $baseline->productSubtotalCents);
        self::assertSame(1000, $baseline->shippingTotalCents);
        self::assertSame(5500, $baseline->grandTotalCents);
        self::assertGreaterThan($baseline->productSubtotalCents, $result->productSubtotalCents);
        self::assertLessThan($baseline->grandTotalCents, $result->grandTotalCents);
    }

    public function testCoffeeQuantitySixIsVerifiedAndSplits(): void
    {
        $result = $this->optimizer->optimize(ScenarioFactory::scenario('coffee-split'));

        self::assertSame(OptimizationStatus::OptimalVerified, $result->status);
        self::assertSame(5, $result->quantityFor('A', 1));
        self::assertSame(1, $result->quantityFor('B', 1));
        self::assertSame(0, $result->quantityFor('C', 1));
        self::assertSame(7120, $result->productSubtotalCents);
        self::assertSame(300, $result->shippingTotalCents);
        self::assertSame(7420, $result->grandTotalCents);
    }

    public function testNoStockScenarioIsInfeasible(): void
    {
        $result = $this->optimizer->optimize(ScenarioFactory::scenario('oats-no-stock'));

        self::assertSame(OptimizationStatus::Infeasible, $result->status);
        self::assertSame([], $result->lines);
        self::assertSame(0, $result->grandTotalCents);
        self::assertStringContainsString('Not enough eligible stock', $result->explanation);
    }

    public function testIneligiblePostcodeRemovesThatVendorBeforeAllocation(): void
    {
        $result = $this->optimizer->optimize(ScenarioFactory::scenario('oats-must-split', '80331'));

        self::assertSame(OptimizationStatus::OptimalVerified, $result->status);
        self::assertSame(2, $result->quantityFor('A', 5));
        self::assertSame(4, $result->quantityFor('B', 5));
        self::assertSame(0, $result->quantityFor('C', 5));
        self::assertSame(5800, $result->grandTotalCents);
    }

    public function testFallbackDoesNotClaimGlobalOptimality(): void
    {
        $optimizer = new CartOptimizer(new ShippingCalculator(), 0);
        $result = $optimizer->optimize(ScenarioFactory::scenario('breakfast-shipping'));

        self::assertSame(OptimizationStatus::FallbackNotProven, $result->status);
        self::assertSame('FALLBACK_NOT_PROVEN_OPTIMAL', $result->status->value);
        self::assertGreaterThan(1, $result->searchSpace);
        self::assertSame(0, $result->combinationsEvaluated);
        self::assertSame(1, $result->quantityFor('A', 2) + $result->quantityFor('B', 2) + $result->quantityFor('C', 2));
        self::assertSame(1, $result->quantityFor('A', 3) + $result->quantityFor('B', 3) + $result->quantityFor('C', 3));
    }

    public function testWeightChangesTheWinningVendor(): void
    {
        $heavy = new ShippingRule(1, 100, null, 400, []);
        $light = new ShippingRule(2, 500, null, 0, []);
        $cart = new CartInput(
            [new CartLine(1, 'Weighted goods', 1)],
            [1 => [
                new OfferOption(1, 1, 'A', 'Heavy surcharge', 1, 1000, 5, 2000, $heavy),
                new OfferOption(2, 2, 'B', 'Flat carrier', 1, 1100, 5, 2000, $light),
            ]],
            '10115',
        );

        $result = $this->optimizer->optimize($cart);

        self::assertSame(OptimizationStatus::OptimalVerified, $result->status);
        self::assertSame(1, $result->quantityFor('B', 1));
        self::assertSame(0, $result->quantityFor('A', 1));
        self::assertSame(1600, $result->grandTotalCents);
    }
}
