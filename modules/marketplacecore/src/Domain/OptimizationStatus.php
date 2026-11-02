<?php

declare(strict_types=1);

namespace Marketplace\Core\Domain;

/**
 * Optimality is a claim about the search the algorithm actually performed.
 */
enum OptimizationStatus: string
{
    case OptimalVerified = 'OPTIMAL_VERIFIED';
    case FallbackNotProven = 'FALLBACK_NOT_PROVEN_OPTIMAL';
    case Infeasible = 'INFEASIBLE';
    case UnitPriceBaseline = 'UNIT_PRICE_BASELINE';
}
