<?php

declare(strict_types=1);

namespace Marketplace\Tests\Shipping;

use Marketplace\Shipping\Service\ShippingCalculator;
use Marketplace\Shipping\Dto\ShippingRule;
use Marketplace\Shipping\Service\ShippingRuleSelector;
use PHPUnit\Framework\TestCase;

final class ShippingRuleSelectorTest extends TestCase
{
    public function testSpecificPrefixBeatsTheNationwideRule(): void
    {
        $chosen = (new ShippingRuleSelector())->choose($this->harborRules(), '80331');

        self::assertNotNull($chosen);
        self::assertSame(150, (int) $chosen['surcharge_cents_per_kg']);
        self::assertSame('80', $chosen['postcode_prefixes']);
    }

    public function testNationwideRuleStaysInForceForTheDemoPostcode(): void
    {
        $chosen = (new ShippingRuleSelector())->choose($this->harborRules(), '10 115');

        self::assertNotNull($chosen);
        self::assertSame(0, (int) $chosen['surcharge_cents_per_kg']);
        self::assertSame('', $chosen['postcode_prefixes']);
    }

    public function testUnmatchedVendorKeepsItsOnlyRuleSoTheQuoteCanRejectThePostcode(): void
    {
        $chosen = (new ShippingRuleSelector())->choose([
            [
                'id' => 3,
                'base_cents' => 700,
                'free_threshold_cents' => 4000,
                'surcharge_cents_per_kg' => 0,
                'postcode_prefixes' => '10,20,75',
            ],
        ], '80331');

        self::assertNotNull($chosen);
        self::assertSame('10,20,75', $chosen['postcode_prefixes']);
    }

    public function testMunichWeightIsChargedBelowTheFreeThreshold(): void
    {
        $quote = (new ShippingCalculator())->quote(new ShippingRule(2, 300, 8000, 150, ['80']), 1120, 1000, '80331');

        self::assertTrue($quote->eligible);
        self::assertSame(450, $quote->amountCents);
        self::assertStringContainsString('weight', $quote->reason);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function harborRules(): array
    {
        return [
            [
                'id' => 2,
                'base_cents' => 300,
                'free_threshold_cents' => 8000,
                'surcharge_cents_per_kg' => 0,
                'postcode_prefixes' => '',
            ],
            [
                'id' => 4,
                'base_cents' => 300,
                'free_threshold_cents' => 8000,
                'surcharge_cents_per_kg' => 150,
                'postcode_prefixes' => '80',
            ],
        ];
    }
}
