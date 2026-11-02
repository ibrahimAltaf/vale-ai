<?php

declare(strict_types=1);

namespace Marketplace\Catalog\Dto;

final class ImportRow
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $vendorCode,
        public readonly string $sku,
        public readonly string $ean,
        public readonly string $brand,
        public readonly string $title,
        public readonly string $description,
        public readonly int $listPriceCents,
        public readonly string $discountPercent,
        public readonly int $stock,
        public readonly int $weightGrams,
        public readonly string $imageUrl,
    ) {
    }
}
