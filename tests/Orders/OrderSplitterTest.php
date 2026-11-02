<?php

declare(strict_types=1);

namespace Marketplace\Tests\Orders;

use Marketplace\Commission\Service\CommissionCalculator;
use Marketplace\Core\Domain\OptimizationStatus;
use Marketplace\Core\Seed\DemoCatalog;
use Marketplace\Optimizer\Demo\ScenarioFactory;
use Marketplace\Optimizer\Service\CartOptimizer;
use Marketplace\Orders\Service\OrderSplitter;
use Marketplace\Shipping\Service\ShippingCalculator;
use PHPUnit\Framework\TestCase;

final class OrderSplitterTest extends TestCase
{
    public function testParentOrderSplitsIntoVendorSubOrdersWithCommission(): void
    {
        $result = (new CartOptimizer(new ShippingCalculator()))->optimize(ScenarioFactory::scenario('oats-must-split'));
        $rates = [];
        foreach (DemoCatalog::vendors() as $vendor) {
            $rates[$vendor['id']] = $vendor['commission'];
        }

        $plan = (new OrderSplitter(new CommissionCalculator()))->split($result, '10115', '1001', $rates);

        self::assertSame('1001', $plan->parentReference);
        self::assertSame(OptimizationStatus::OptimalVerified->value, $plan->optimizationStatus);
        self::assertSame(5800, $plan->grandCents);
        self::assertCount(2, $plan->vendorOrders);
        self::assertSame('1001-A', $plan->vendorOrders[0]->reference);
        self::assertSame('1001-B', $plan->vendorOrders[1]->reference);

        self::assertSame(1600, $plan->vendorOrders[0]->productCents);
        self::assertSame(160, $plan->vendorOrders[0]->commissionCents);
        self::assertSame(1440, $plan->vendorOrders[0]->netCents);

        self::assertSame(3400, $plan->vendorOrders[1]->productCents);
        self::assertSame(408, $plan->vendorOrders[1]->commissionCents);
        self::assertSame(2992, $plan->vendorOrders[1]->netCents);
    }
}
