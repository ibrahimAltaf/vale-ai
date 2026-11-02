<?php

declare(strict_types=1);

namespace Marketplace\Orders\Exception;

final class InsufficientStockException extends \RuntimeException
{
    public function __construct(
        public readonly int $offerId,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Offer %d cannot reserve %d units. Available: %d.',
            $offerId,
            $requested,
            $available,
        ));
    }
}
