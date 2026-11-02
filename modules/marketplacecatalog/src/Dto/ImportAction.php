<?php

declare(strict_types=1);

namespace Marketplace\Catalog\Dto;

final class ImportAction
{
    public const UPDATE = 'UPDATE';
    public const CREATE_OFFER = 'CREATE_OFFER';
    public const CREATE_MASTER = 'CREATE_MASTER';
    public const REJECT = 'REJECT';

    public function __construct(
        public readonly string $type,
        public readonly int $lineNumber,
        public readonly string $vendorCode,
        public readonly string $sku,
        public readonly string $message,
        public readonly ?int $productId = null,
        public readonly ?int $existingOfferId = null,
        public readonly ?ImportRow $row = null,
        public readonly ?MatchResult $match = null,
    ) {
    }
}
