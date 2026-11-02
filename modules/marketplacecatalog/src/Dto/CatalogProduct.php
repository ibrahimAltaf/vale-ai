<?php

declare(strict_types=1);

namespace Marketplace\Catalog\Dto;

final class CatalogProduct
{
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $ean,
        public readonly string $name,
    ) {
    }
}
