<?php

declare(strict_types=1);

namespace Marketplace\Tests\Matching;

use Marketplace\Catalog\Dto\CatalogProduct;
use Marketplace\Catalog\Dto\MatchType;
use Marketplace\Catalog\Service\ProductMatcher;
use PHPUnit\Framework\TestCase;

final class ProductMatcherTest extends TestCase
{
    private ProductMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ProductMatcher();
    }

    public function testExactMatchByEan(): void
    {
        $result = $this->matcher->match('1234567890123', 'OTHER', $this->catalog());

        self::assertSame(MatchType::Exact, $result->type);
        self::assertSame('EAN', $result->matchedOn);
        self::assertSame([1], $result->candidateProductIds);
    }

    public function testExactMatchBySkuWhenEanIsUnknown(): void
    {
        $result = $this->matcher->match('000', 'COFFEE-1KG', $this->catalog());

        self::assertSame(MatchType::Exact, $result->type);
        self::assertSame('SKU', $result->matchedOn);
        self::assertSame([1], $result->candidateProductIds);
    }

    public function testNoMatch(): void
    {
        $result = $this->matcher->match('999', 'MISSING', $this->catalog());

        self::assertSame(MatchType::None, $result->type);
        self::assertSame([], $result->candidateProductIds);
    }

    public function testDuplicateEanIsAmbiguousAndNotMerged(): void
    {
        $catalog = $this->catalog();
        $catalog[] = new CatalogProduct(9, 'COFFEE-ALT', '1234567890123', 'Second coffee');

        $result = $this->matcher->match('1234567890123', 'COFFEE-ALT', $catalog);

        self::assertSame(MatchType::Ambiguous, $result->type);
        self::assertSame('EAN', $result->matchedOn);
        self::assertEqualsCanonicalizing([1, 9], $result->candidateProductIds);
    }

    public function testEanAndSkuPointingAtDifferentProductsIsAmbiguous(): void
    {
        $result = $this->matcher->match('1234567890123', 'OIL-500', $this->catalog());

        self::assertSame(MatchType::Ambiguous, $result->type);
        self::assertSame('EAN+SKU', $result->matchedOn);
        self::assertEqualsCanonicalizing([1, 4], $result->candidateProductIds);
    }

    public function testDuplicateSkuIsAmbiguous(): void
    {
        $catalog = $this->catalog();
        $catalog[] = new CatalogProduct(8, 'OIL-500', '555', 'Duplicate oil');

        $result = $this->matcher->match('', 'oil-500', $catalog);

        self::assertSame(MatchType::Ambiguous, $result->type);
        self::assertSame('SKU', $result->matchedOn);
        self::assertEqualsCanonicalizing([4, 8], $result->candidateProductIds);
    }

    /**
     * @return list<CatalogProduct>
     */
    private function catalog(): array
    {
        return [
            new CatalogProduct(1, 'COFFEE-1KG', '1234567890123', 'Premium Coffee 1kg'),
            new CatalogProduct(4, 'OIL-500', '1234567890126', 'Ligurian Olive Oil 500ml'),
        ];
    }
}
