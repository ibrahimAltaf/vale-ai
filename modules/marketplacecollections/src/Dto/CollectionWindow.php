<?php

declare(strict_types=1);

namespace Marketplace\Collections\Dto;

final class CollectionWindow
{
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly bool $enabled,
        public readonly ?\DateTimeImmutable $startsAt,
        public readonly ?\DateTimeImmutable $endsAt,
    ) {
    }
}
