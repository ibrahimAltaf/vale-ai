<?php

declare(strict_types=1);

namespace Marketplace\Optimizer\Dto;

final class CartInput
{
    /**
     * @param list<CartLine> $lines
     * @param array<int, list<OfferOption>> $offersByProductId
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $offersByProductId,
        public readonly string $postcode,
    ) {
    }
}
