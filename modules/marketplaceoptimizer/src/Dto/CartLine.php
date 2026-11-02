<?php

declare(strict_types=1);

namespace Marketplace\Optimizer\Dto;

final class CartLine
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        public readonly int $quantity,
    ) {
    }
}
