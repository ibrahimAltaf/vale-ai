<?php

declare(strict_types=1);

namespace Marketplace\Catalog\Dto;

final class ImportPlan
{
    /**
     * @param list<ImportAction> $actions
     */
    public function __construct(public readonly array $actions)
    {
    }

    public function count(string $type): int
    {
        return count(array_filter($this->actions, static fn (ImportAction $action): bool => $action->type === $type));
    }
}
