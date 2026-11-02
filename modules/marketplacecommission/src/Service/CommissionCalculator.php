<?php

declare(strict_types=1);

namespace Marketplace\Commission\Service;

use Marketplace\Commission\Dto\CommissionBreakdown;
use Marketplace\Core\Domain\Money;

/**
 * Commission is calculated on vendor product sales. Shipping is excluded.
 */
final class CommissionCalculator
{
    public function calculate(int $grossCents, string $percent): CommissionBreakdown
    {
        if ($grossCents < 0) {
            throw new \InvalidArgumentException('Gross amount cannot be negative.');
        }

        $commission = Money::percentOf($grossCents, $percent);

        return new CommissionBreakdown($grossCents, $this->normalize($percent), $commission, $grossCents - $commission);
    }

    private function normalize(string $percent): string
    {
        $basisPoints = Money::basisPoints($percent);

        return intdiv($basisPoints, 100) . '.' . str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }
}
