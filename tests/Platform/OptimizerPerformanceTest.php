<?php

declare(strict_types=1);

namespace Marketplace\Tests\Platform;

use Marketplace\Core\Domain\OptimizationStatus;
use Marketplace\Optimizer\Demo\ScenarioFactory;
use Marketplace\Optimizer\Service\CartOptimizer;
use Marketplace\Shipping\Service\ShippingCalculator;
use PHPUnit\Framework\TestCase;

final class OptimizerPerformanceTest extends TestCase
{
    public function testBreakfastScenarioStaysFastAndOptimal(): void
    {
        $optimizer = new CartOptimizer(new ShippingCalculator());
        $cart = ScenarioFactory::scenario('breakfast-shipping');
        $started = hrtime(true);
        $result = null;
        for ($i = 0; $i < 200; $i++) {
            $result = $optimizer->optimize($cart);
        }
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        self::assertNotNull($result);
        self::assertSame(OptimizationStatus::OptimalVerified, $result->status);
        self::assertSame(5200, $result->grandTotalCents);
        self::assertLessThan(5.0, $elapsed, '200 breakfast optimizations took ' . $elapsed . ' seconds.');
    }
}
