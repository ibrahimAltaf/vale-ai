<?php

declare(strict_types=1);

namespace Marketplace\Catalog\Service;

use Marketplace\Catalog\Dto\CatalogProduct;
use Marketplace\Catalog\Dto\MatchResult;
use Marketplace\Catalog\Dto\MatchType;

/**
 * Matches a vendor row onto the master catalog by EAN, then by SKU/reference.
 * Ambiguous results are returned as-is and must not be merged by the caller.
 */
final class ProductMatcher
{
    /**
     * @param list<CatalogProduct> $catalog
     */
    public function match(?string $ean, ?string $sku, array $catalog): MatchResult
    {
        $ean = $this->normalize($ean);
        $sku = $this->normalize($sku);

        $byEan = $ean === '' ? [] : $this->filter($catalog, static fn (CatalogProduct $product): bool => self::normalize($product->ean) === $ean);
        $bySku = $sku === '' ? [] : $this->filter($catalog, static fn (CatalogProduct $product): bool => self::normalize($product->sku) === $sku);

        if (count($byEan) > 1) {
            return new MatchResult(MatchType::Ambiguous, 'EAN', $this->ids($byEan));
        }

        if (count($byEan) === 1) {
            if (count($bySku) > 1 || (count($bySku) === 1 && $bySku[0]->id !== $byEan[0]->id)) {
                $ids = array_values(array_unique(array_merge($this->ids($byEan), $this->ids($bySku))));

                return new MatchResult(MatchType::Ambiguous, 'EAN+SKU', $ids);
            }

            return new MatchResult(MatchType::Exact, 'EAN', [$byEan[0]->id]);
        }

        if (count($bySku) > 1) {
            return new MatchResult(MatchType::Ambiguous, 'SKU', $this->ids($bySku));
        }

        if (count($bySku) === 1) {
            return new MatchResult(MatchType::Exact, 'SKU', [$bySku[0]->id]);
        }

        return new MatchResult(MatchType::None, 'NONE', []);
    }

    /**
     * @param list<CatalogProduct> $catalog
     * @param callable(CatalogProduct): bool $predicate
     * @return list<CatalogProduct>
     */
    private function filter(array $catalog, callable $predicate): array
    {
        return array_values(array_filter($catalog, $predicate));
    }

    /**
     * @param list<CatalogProduct> $products
     * @return list<int>
     */
    private function ids(array $products): array
    {
        return array_map(static fn (CatalogProduct $product): int => $product->id, $products);
    }

    private static function normalize(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }
}
