<?php

declare(strict_types=1);

namespace Marketplace\Tests\Commission;

use Marketplace\Commission\Service\CommissionCalculator;
use PHPUnit\Framework\TestCase;

final class CommissionCalculatorTest extends TestCase
{
    private CommissionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CommissionCalculator();
    }

    public function testTenPercentOfOneHundredEuros(): void
    {
        $result = $this->calculator->calculate(10000, '10');

        self::assertSame(10000, $result->grossCents);
        self::assertSame(1000, $result->commissionCents);
        self::assertSame(9000, $result->netCents);
        self::assertSame('10.00', $result->percent);
    }

    public function testVendorRatesFromTheDemoCatalog(): void
    {
        self::assertSame(600, $this->calculator->calculate(5000, '12.00')->commissionCents);
        self::assertSame(4400, $this->calculator->calculate(5000, '12.00')->netCents);
        self::assertSame(400, $this->calculator->calculate(5000, '8')->commissionCents);
        self::assertSame(4600, $this->calculator->calculate(5000, '8')->netCents);
    }

    public function testHalfUpRounding(): void
    {
        $result = $this->calculator->calculate(100, '12.50');

        self::assertSame(13, $result->commissionCents);
        self::assertSame(87, $result->netCents);
    }
}
