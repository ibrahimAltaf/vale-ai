<?php

declare(strict_types=1);

namespace Marketplace\Optimizer\Demo;

use Marketplace\Core\Domain\Money;
use Marketplace\Core\Seed\DemoCatalog;
use Marketplace\Optimizer\Dto\CartInput;
use Marketplace\Optimizer\Dto\CartLine;
use Marketplace\Optimizer\Dto\OfferOption;
use Marketplace\Shipping\Dto\ShippingRule;

final class ScenarioFactory
{
    /**
     * @param array<string, int> $quantitiesBySku
     */
    public static function cart(array $quantitiesBySku, string $postcode): CartInput
    {
        $lines = [];
        $offers = [];
        foreach (DemoCatalog::products() as $product) {
            if (!isset($quantitiesBySku[$product['sku']])) {
                continue;
            }
            $lines[] = new CartLine($product['id'], $product['name'], $quantitiesBySku[$product['sku']]);
            $offers[$product['id']] = self::offersFor($product);
        }

        return new CartInput($lines, $offers, $postcode);
    }

    public static function scenario(string $name, string $postcode = '10115'): CartInput
    {
        $quantities = DemoCatalog::scenarioQuantities()[$name] ?? null;
        if ($quantities === null) {
            throw new \InvalidArgumentException('Unknown scenario ' . $name);
        }

        return self::cart($quantities, $postcode);
    }

    /**
     * @param array{id: int, name: string, weight_grams: int, offers: list<array{id: int, vendor_id: int, list_cents: int, discount: string, stock: int}>} $product
     * @return list<OfferOption>
     */
    public static function offersFor(array $product): array
    {
        $options = [];
        foreach ($product['offers'] as $offer) {
            $vendor = DemoCatalog::vendorById($offer['vendor_id']);
            $rule = $vendor['shipping'];
            $options[] = new OfferOption(
                $offer['id'],
                $vendor['id'],
                $vendor['code'],
                $vendor['name'],
                $product['id'],
                Money::afterDiscount($offer['list_cents'], $offer['discount']),
                $offer['stock'],
                $product['weight_grams'],
                new ShippingRule(
                    $vendor['id'],
                    $rule['base_cents'],
                    $rule['free_threshold_cents'],
                    $rule['surcharge_cents_per_kg'],
                    $rule['postcode_prefixes'],
                ),
            );
        }

        return $options;
    }
}
