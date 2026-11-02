<?php

declare(strict_types=1);

namespace Marketplace\Tests\Shipping;

use Marketplace\Shipping\Dto\ShippingRule;
use Marketplace\Shipping\Service\ShippingCalculator;
use PHPUnit\Framework\TestCase;

final class ShippingCalculatorTest extends TestCase
{
    private ShippingCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ShippingCalculator();
    }

    public function testFixedBaseShipping(): void
    {
        $quote = $this->calculator->quote($this->rule(500, null, 0, []), 1000, 0, '10115');

        self::assertTrue($quote->eligible);
        self::assertSame(500, $quote->amountCents);
        self::assertFalse($quote->freeShippingApplied);
    }

    public function testFreeShippingAtThreshold(): void
    {
        $quote = $this->calculator->quote($this->rule(500, 5000, 0, []), 5000, 1000, '10115');

        self::assertSame(0, $quote->amountCents);
        self::assertTrue($quote->freeShippingApplied);
    }

    public function testShippingChargedBelowThreshold(): void
    {
        $quote = $this->calculator->quote($this->rule(500, 5000, 0, []), 4999, 1000, '10115');

        self::assertSame(500, $quote->amountCents);
        self::assertFalse($quote->freeShippingApplied);
    }

    public function testWeightSurcharge(): void
    {
        $quote = $this->calculator->quote($this->rule(500, null, 200, []), 1000, 1500, '10115');

        self::assertSame(800, $quote->amountCents);
        self::assertStringContainsString('weight', $quote->reason);
    }

    public function testWeightSurchargeIsWaivedWhenThresholdIsReached(): void
    {
        $quote = $this->calculator->quote($this->rule(500, 5000, 200, []), 5000, 1500, '10115');

        self::assertSame(0, $quote->amountCents);
        self::assertTrue($quote->freeShippingApplied);
    }

    public function testPostcodePrefixEligibility(): void
    {
        $rule = $this->rule(700, 4000, 0, ['10', '20', '75']);

        self::assertTrue($this->calculator->quote($rule, 1000, 0, '10115')->eligible);
        self::assertTrue($this->calculator->quote($rule, 1000, 0, '20 100')->eligible);
        self::assertFalse($this->calculator->quote($rule, 1000, 0, '80331')->eligible);
        self::assertSame(0, $this->calculator->quote($rule, 1000, 0, '80331')->amountCents);
    }

    public function testEmptyPrefixListAllowsEveryPostcode(): void
    {
        $quote = $this->calculator->quote($this->rule(300, 8000, 0, []), 1000, 0, '80331');

        self::assertTrue($quote->eligible);
        self::assertSame(300, $quote->amountCents);
    }

    /**
     * @param list<string> $prefixes
     */
    private function rule(int $base, ?int $threshold, int $perKg, array $prefixes): ShippingRule
    {
        return new ShippingRule(1, $base, $threshold, $perKg, $prefixes);
    }
}
