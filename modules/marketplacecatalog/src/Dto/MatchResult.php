<?php

declare(strict_types=1);

namespace Marketplace\Catalog\Dto;

enum MatchType: string
{
    case Exact = 'EXACT_MATCH';
    case None = 'NO_MATCH';
    case Ambiguous = 'AMBIGUOUS_MATCH';
}

final class MatchResult
{
    /**
     * @param list<int> $candidateProductIds
     */
    public function __construct(
        public readonly MatchType $type,
        public readonly string $matchedOn,
        public readonly array $candidateProductIds,
    ) {
    }
}
