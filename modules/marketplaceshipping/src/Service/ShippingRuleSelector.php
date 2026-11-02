<?php

declare(strict_types=1);

namespace Marketplace\Shipping\Service;

/**
 * Picks one active rule for a vendor and postcode.
 * A longer matching prefix wins over a nationwide rule.
 * The nationwide demo rules therefore stay in force outside their extra areas.
 */
final class ShippingRuleSelector
{
    /**
     * @param list<array<string, mixed>> $rules
     * @return array<string, mixed>|null
     */
    public function choose(array $rules, string $postcode): ?array
    {
        if ($rules === []) {
            return null;
        }

        $postcode = $this->normalize($postcode);
        $eligible = [];
        foreach ($rules as $rule) {
            $prefixes = $this->prefixes((string) ($rule['postcode_prefixes'] ?? ''));
            if ($postcode === '') {
                if ($prefixes === []) {
                    $eligible[] = ['rule' => $rule, 'score' => 0];
                }
                continue;
            }
            $score = $this->score($prefixes, $postcode);
            if ($score !== null) {
                $eligible[] = ['rule' => $rule, 'score' => $score];
            }
        }

        if ($eligible === []) {
            return $rules[0];
        }

        usort($eligible, static function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }

            return ((int) ($left['rule']['id'] ?? $left['rule']['id_marketplace_shipping_rule'] ?? 0))
                <=> ((int) ($right['rule']['id'] ?? $right['rule']['id_marketplace_shipping_rule'] ?? 0));
        });

        return $eligible[0]['rule'];
    }

    /**
     * @return list<string>
     */
    private function prefixes(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $prefixes = [];
        foreach (explode(',', $value) as $prefix) {
            $prefix = $this->normalize($prefix);
            if ($prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    /**
     * @param list<string> $prefixes
     */
    private function score(array $prefixes, string $postcode): ?int
    {
        if ($prefixes === []) {
            return 0;
        }
        $best = null;
        foreach ($prefixes as $prefix) {
            if (str_starts_with($postcode, $prefix) && ($best === null || strlen($prefix) > $best)) {
                $best = strlen($prefix);
            }
        }

        return $best;
    }

    private function normalize(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }
}
