<?php

declare(strict_types=1);

namespace Marketplace\Optimizer\Service;

use Marketplace\Core\Domain\Money;
use Marketplace\Core\Domain\OptimizationStatus;
use Marketplace\Optimizer\Dto\AllocationLine;
use Marketplace\Optimizer\Dto\CartInput;
use Marketplace\Optimizer\Dto\CartLine;
use Marketplace\Optimizer\Dto\OfferOption;
use Marketplace\Optimizer\Dto\OptimizationResult;
use Marketplace\Optimizer\Dto\VendorAllocation;
use Marketplace\Shipping\Service\ShippingCalculator;

/**
 * Allocates cart lines across vendor offers.
 *
 * When every feasible quantity distribution fits inside the configured bound,
 * each one is priced and the lowest total checkout cost is returned as
 * OPTIMAL_VERIFIED. Above that bound a deterministic greedy fill is used and
 * reported as FALLBACK_NOT_PROVEN_OPTIMAL. Cost is product cost plus shipping.
 */
final class CartOptimizer
{
    /** @var array<int, OfferOption> */
    private array $offerIndex = [];

    public function __construct(
        private readonly ShippingCalculator $shipping,
        private readonly int $maxCombinations = 200000,
    ) {
        if ($maxCombinations < 0) {
            throw new \InvalidArgumentException('Combination limit cannot be negative.');
        }
    }

    public function optimize(CartInput $cart): OptimizationResult
    {
        $prepared = $this->prepare($cart);
        if ($prepared instanceof OptimizationResult) {
            return $prepared;
        }

        [$distributionSets, $offerSets] = $prepared;
        $this->indexOffers($offerSets);

        $space = 1;
        foreach ($distributionSets as $set) {
            $count = count($set);
            if ($count === 0 || $space > intdiv(PHP_INT_MAX, $count)) {
                $space = PHP_INT_MAX;
                break;
            }
            $space *= $count;
        }

        if ($space <= $this->maxCombinations) {
            return $this->enumerate($cart, $distributionSets, $offerSets, $space);
        }

        return $this->fallback($cart, $offerSets, $space);
    }

    /**
     * Fills each line from the lowest unit price, then prices shipping.
     * The status is UNIT_PRICE_BASELINE and is not an optimality claim.
     */
    public function unitPriceBaseline(CartInput $cart): OptimizationResult
    {
        $prepared = $this->prepare($cart);
        if ($prepared instanceof OptimizationResult) {
            return $prepared;
        }

        [, $offerSets] = $prepared;
        $this->indexOffers($offerSets);
        $quantities = [];

        foreach ($cart->lines as $lineIndex => $line) {
            $offers = $offerSets[$lineIndex];
            usort($offers, static function (OfferOption $left, OfferOption $right): int {
                return [$left->unitPriceCents, $left->vendorId] <=> [$right->unitPriceCents, $right->vendorId];
            });

            $remaining = $line->quantity;
            foreach ($offers as $offer) {
                $take = min($remaining, $offer->stock);
                if ($take < 1) {
                    continue;
                }
                $quantities[$lineIndex][$offer->offerId] = $take;
                $remaining -= $take;
                if ($remaining === 0) {
                    break;
                }
            }
        }

        $priced = $this->price($cart->postcode, $this->linesFromMap($cart, $offerSets, $quantities));
        if ($priced === null) {
            return $this->infeasible('The cheapest-unit allocation is not shippable to this postcode.');
        }

        return $this->finish(
            $priced['vendors'],
            $priced['lines'],
            OptimizationStatus::UnitPriceBaseline,
            1,
            1,
            'Baseline that fills each product from the lowest unit price, then adds shipping.',
        );
    }

    /**
     * @return OptimizationResult|array{0: list<list<array<int, int>>>, 1: list<list<OfferOption>>}
     */
    private function prepare(CartInput $cart): OptimizationResult|array
    {
        if ($cart->lines === []) {
            return $this->infeasible('Add at least one product before optimizing.');
        }

        $distributionSets = [];
        $offerSets = [];
        foreach ($cart->lines as $line) {
            if ($line->quantity < 1) {
                return $this->infeasible('Quantities must be at least 1.');
            }

            $offers = $this->eligibleOffers($cart->offersByProductId[$line->productId] ?? [], $cart->postcode);
            $available = 0;
            foreach ($offers as $offer) {
                $available += $offer->stock;
            }
            if ($available < $line->quantity) {
                return $this->infeasible(sprintf(
                    'Not enough eligible stock for %s. Requested %d, available %d.',
                    $line->productName,
                    $line->quantity,
                    $available,
                ));
            }

            $distributionSets[] = $this->distributions($line->quantity, $offers);
            $offerSets[] = $offers;
        }

        return [$distributionSets, $offerSets];
    }

    /**
     * @param list<OfferOption> $offers
     * @return list<OfferOption>
     */
    private function eligibleOffers(array $offers, string $postcode): array
    {
        $eligible = [];
        foreach ($offers as $offer) {
            if ($offer->stock < 1) {
                continue;
            }
            if (!$this->shipping->postcodeAllowed($offer->shipping->postcodePrefixes, $postcode)) {
                continue;
            }
            $eligible[] = $offer;
        }

        usort($eligible, static fn (OfferOption $left, OfferOption $right): int => $left->vendorId <=> $right->vendorId);

        return $eligible;
    }

    /**
     * @param list<OfferOption> $offers
     * @return list<array<int, int>>
     */
    private function distributions(int $quantity, array $offers): array
    {
        $results = [];
        $current = [];
        $this->walk($offers, $quantity, 0, $current, $results);

        return $results;
    }

    /**
     * @param list<OfferOption> $offers
     * @param array<int, int> $current
     * @param list<array<int, int>> $results
     */
    private function walk(array $offers, int $remaining, int $index, array &$current, array &$results): void
    {
        $offer = $offers[$index];
        $laterStock = 0;
        for ($cursor = $index + 1, $count = count($offers); $cursor < $count; ++$cursor) {
            $laterStock += $offers[$cursor]->stock;
        }

        $minTake = max(0, $remaining - $laterStock);
        $maxTake = min($offer->stock, $remaining);
        $isLast = $index === count($offers) - 1;

        for ($take = $minTake; $take <= $maxTake; ++$take) {
            $current[$offer->offerId] = $take;
            if ($isLast) {
                if ($take === $remaining) {
                    $results[] = $current;
                }
                continue;
            }
            $this->walk($offers, $remaining - $take, $index + 1, $current, $results);
        }
    }

    /**
     * @param list<list<array<int, int>>> $distributionSets
     * @param list<list<OfferOption>> $offerSets
     */
    private function enumerate(CartInput $cart, array $distributionSets, array $offerSets, int $space): OptimizationResult
    {
        $best = null;
        $bestSignature = '';
        $evaluated = 0;
        $indexes = array_fill(0, count($distributionSets), 0);
        $sizes = array_map(static fn (array $set): int => count($set), $distributionSets);

        while (true) {
            $map = [];
            foreach ($indexes as $lineIndex => $choice) {
                $map[$lineIndex] = $distributionSets[$lineIndex][$choice];
            }

            $priced = $this->price($cart->postcode, $this->linesFromMap($cart, $offerSets, $map));
            ++$evaluated;
            if ($priced !== null) {
                $signature = $this->signature($priced['lines']);
                if ($best === null || $this->isBetter($priced, $signature, $best, $bestSignature)) {
                    $best = $priced;
                    $bestSignature = $signature;
                }
            }

            $cursor = count($indexes) - 1;
            while ($cursor >= 0) {
                ++$indexes[$cursor];
                if ($indexes[$cursor] < $sizes[$cursor]) {
                    break;
                }
                $indexes[$cursor] = 0;
                --$cursor;
            }
            if ($cursor < 0) {
                break;
            }
        }

        if ($best === null) {
            return $this->infeasible('No shippable allocation exists for this postcode.', $evaluated, $space);
        }

        return $this->finish(
            $best['vendors'],
            $best['lines'],
            OptimizationStatus::OptimalVerified,
            $evaluated,
            $space,
            sprintf('Evaluated all %d feasible allocations. The selected checkout total is proven lowest.', $evaluated),
        );
    }

    /**
     * @param list<list<OfferOption>> $offerSets
     */
    private function fallback(CartInput $cart, array $offerSets, int $space): OptimizationResult
    {
        $remaining = [];
        foreach ($cart->lines as $lineIndex => $line) {
            $remaining[$lineIndex] = $line->quantity;
        }

        /** @var array<int, array<int, int>> $quantities */
        $quantities = [];
        $guard = 0;
        $limit = max(1, array_sum($remaining));

        while (array_sum($remaining) > 0) {
            if (++$guard > $limit) {
                return $this->infeasible('Fallback search could not fill the cart.', 0, $space);
            }

            $bestMove = null;
            foreach ($cart->lines as $lineIndex => $line) {
                if ($remaining[$lineIndex] < 1) {
                    continue;
                }
                foreach ($offerSets[$lineIndex] as $offer) {
                    $used = $quantities[$lineIndex][$offer->offerId] ?? 0;
                    if ($used >= $offer->stock) {
                        continue;
                    }

                    $tentative = $quantities;
                    $tentative[$lineIndex][$offer->offerId] = $used + 1;
                    $priced = $this->price($cart->postcode, $this->linesFromMap($cart, $offerSets, $tentative));
                    if ($priced === null) {
                        continue;
                    }

                    $rank = [$priced['grand'], $offer->vendorId, $line->productId];
                    if ($bestMove === null || $rank < $bestMove['rank']) {
                        $bestMove = [
                            'rank' => $rank,
                            'line' => $lineIndex,
                            'offer' => $offer->offerId,
                        ];
                    }
                }
            }

            if ($bestMove === null) {
                return $this->infeasible('Fallback search found no shippable next unit.', 0, $space);
            }

            $quantities[$bestMove['line']][$bestMove['offer']] = ($quantities[$bestMove['line']][$bestMove['offer']] ?? 0) + 1;
            --$remaining[$bestMove['line']];
        }

        $priced = $this->price($cart->postcode, $this->linesFromMap($cart, $offerSets, $quantities));
        if ($priced === null) {
            return $this->infeasible('Fallback allocation is not shippable.', 0, $space);
        }

        return $this->finish(
            $priced['vendors'],
            $priced['lines'],
            OptimizationStatus::FallbackNotProven,
            0,
            $space,
            sprintf(
                'Search space of %d allocations exceeds the verified bound of %d. This result is a feasible fallback, not a proven optimum.',
                $space,
                $this->maxCombinations,
            ),
        );
    }

    /**
     * @param list<list<OfferOption>> $offerSets
     */
    private function indexOffers(array $offerSets): void
    {
        $this->offerIndex = [];
        foreach ($offerSets as $offers) {
            foreach ($offers as $offer) {
                $this->offerIndex[$offer->offerId] = $offer;
            }
        }
    }

    /**
     * @param list<list<OfferOption>> $offerSets
     * @param array<int, array<int, int>> $quantities
     * @return list<AllocationLine>
     */
    private function linesFromMap(CartInput $cart, array $offerSets, array $quantities): array
    {
        $lines = [];
        foreach ($cart->lines as $lineIndex => $line) {
            foreach ($offerSets[$lineIndex] as $offer) {
                $quantity = $quantities[$lineIndex][$offer->offerId] ?? 0;
                if ($quantity < 1) {
                    continue;
                }
                $lines[] = new AllocationLine(
                    $line->productId,
                    $line->productName,
                    $offer->offerId,
                    $offer->vendorId,
                    $offer->vendorCode,
                    $offer->vendorName,
                    $quantity,
                    $offer->unitPriceCents,
                    $offer->unitPriceCents * $quantity,
                    $offer->weightGrams * $quantity,
                );
            }
        }

        return $lines;
    }

    /**
     * @param list<AllocationLine> $lines
     * @return array{vendors: list<VendorAllocation>, lines: list<AllocationLine>, grand: int}|null
     */
    private function price(string $postcode, array $lines): ?array
    {
        /** @var array<int, list<AllocationLine>> $grouped */
        $grouped = [];
        foreach ($lines as $line) {
            $grouped[$line->vendorId][] = $line;
        }
        ksort($grouped);

        $vendors = [];
        foreach ($grouped as $vendorLines) {
            $first = $vendorLines[0];
            $offer = $this->offerIndex[$first->offerId] ?? null;
            if (!$offer instanceof OfferOption) {
                return null;
            }

            $product = 0;
            $weight = 0;
            $volume = 0;
            $palletOnly = false;
            foreach ($vendorLines as $vendorLine) {
                $product += $vendorLine->lineTotalCents;
                $weight += $vendorLine->weightGrams;
                $lineOffer = $this->offerIndex[$vendorLine->offerId] ?? null;
                if (!$lineOffer instanceof OfferOption) {
                    return null;
                }
                $volume += $lineOffer->volumeCm3 * $vendorLine->quantity;
                $palletOnly = $palletOnly || $lineOffer->palletOnly;
            }
            if ($offer->shipping->minOrderEnabled && $product < $offer->shipping->minOrderCents) {
                return null;
            }

            $quote = $this->shipping->quote($offer->shipping, $product, $weight, $postcode, $volume, $palletOnly);
            if (!$quote->eligible) {
                return null;
            }

            $vendors[] = new VendorAllocation(
                $first->vendorId,
                $first->vendorCode,
                $first->vendorName,
                $product,
                $quote->amountCents,
                $weight,
                $quote->freeShippingApplied,
                $quote->reason,
                $vendorLines,
            );
        }

        $grand = 0;
        foreach ($vendors as $vendor) {
            $grand += $vendor->grandTotalCents();
        }

        return ['vendors' => $vendors, 'lines' => $lines, 'grand' => $grand];
    }

    /**
     * @param array{vendors: list<VendorAllocation>, lines: list<AllocationLine>, grand: int} $candidate
     * @param array{vendors: list<VendorAllocation>, lines: list<AllocationLine>, grand: int} $current
     */
    private function isBetter(array $candidate, string $candidateSignature, array $current, string $currentSignature): bool
    {
        return [
            $candidate['grand'],
            count($candidate['vendors']),
            $candidate['grand'] - $this->productSum($candidate['lines']),
            $candidateSignature,
        ] < [
            $current['grand'],
            count($current['vendors']),
            $current['grand'] - $this->productSum($current['lines']),
            $currentSignature,
        ];
    }

    /**
     * @param list<AllocationLine> $lines
     */
    private function signature(array $lines): string
    {
        $parts = [];
        foreach ($lines as $line) {
            $parts[] = $line->vendorId . ':' . $line->productId . ':' . $line->quantity;
        }
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @param list<AllocationLine> $lines
     */
    private function productSum(array $lines): int
    {
        $sum = 0;
        foreach ($lines as $line) {
            $sum += $line->lineTotalCents;
        }

        return $sum;
    }

    /**
     * @param list<VendorAllocation> $vendors
     * @param list<AllocationLine> $lines
     */
    private function finish(
        array $vendors,
        array $lines,
        OptimizationStatus $status,
        int $evaluated,
        int $space,
        string $explanation,
    ): OptimizationResult {
        $product = $this->productSum($lines);
        $shipping = 0;
        $parts = [];
        foreach ($vendors as $vendor) {
            $shipping += $vendor->shippingCents;
            $bits = [];
            foreach ($vendor->lines as $line) {
                $bits[] = $line->quantity . ' × ' . $line->productName;
            }
            $parts[] = implode(', ', $bits)
                . ' from ' . $vendor->vendorName
                . ' (products ' . Money::euros($vendor->productSubtotalCents)
                . ', shipping ' . Money::euros($vendor->shippingCents) . ')';
        }

        return new OptimizationResult(
            $status,
            $product,
            $shipping,
            $product + $shipping,
            $lines,
            $vendors,
            $evaluated,
            $space,
            $explanation . ' ' . implode('; ', $parts) . '. Total ' . Money::euros($product + $shipping) . '.',
        );
    }

    private function infeasible(string $reason, int $evaluated = 0, int $space = 0): OptimizationResult
    {
        return new OptimizationResult(
            OptimizationStatus::Infeasible,
            0,
            0,
            0,
            [],
            [],
            $evaluated,
            $space,
            $reason,
        );
    }
}
