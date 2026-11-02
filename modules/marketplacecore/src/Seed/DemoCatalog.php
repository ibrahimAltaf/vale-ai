<?php

declare(strict_types=1);

namespace Marketplace\Core\Seed;

/**
 * Single source of demo vendors, master products, offers, shipping and collections.
 * Prices are integer cents. Weights are grams. The seeder and the optimizer tests
 * both read this catalog so the interactive shop matches the verified scenarios.
 */
final class DemoCatalog
{
    /**
     * @return list<array{
     *   id: int,
     *   code: string,
     *   name: string,
     *   slug: string,
     *   email: string,
     *   password: string,
     *   commission: string,
     *   description: string,
     *   contact_name: string,
     *   phone: string,
     *   shipping: array{base_cents: int, free_threshold_cents: int, surcharge_cents_per_kg: int, postcode_prefixes: list<string>}
     * }>
     */
    public static function vendors(): array
    {
        return [
            [
                'id' => 1,
                'code' => 'A',
                'name' => 'Alpine Roast',
                'slug' => 'alpine-roast',
                'email' => 'alpine@demo.local',
                'password' => 'AlpineDemo123!',
                'commission' => '10.00',
                'description' => 'Berlin roastery. Breakfast staples and household lines, packed for nationwide delivery. Shipping €5.00, free above €50.00.',
                'contact_name' => 'Marta Keller',
                'phone' => '+49 30 000000',
                'shipping' => [
                    'base_cents' => 500,
                    'free_threshold_cents' => 5000,
                    'surcharge_cents_per_kg' => 0,
                    'postcode_prefixes' => [],
                ],
            ],
            [
                'id' => 2,
                'code' => 'B',
                'name' => 'Harbor Bean',
                'slug' => 'harbor-bean',
                'email' => 'harbor@demo.local',
                'password' => 'HarborDemo123!',
                'commission' => '12.00',
                'description' => 'Hamburg grocer. Pasta, rice, coffee and home goods from one warehouse. Shipping €3.00, free above €80.00.',
                'contact_name' => 'Jonas Krug',
                'phone' => '+49 40 000000',
                'shipping' => [
                    'base_cents' => 300,
                    'free_threshold_cents' => 8000,
                    'surcharge_cents_per_kg' => 0,
                    'postcode_prefixes' => [],
                ],
            ],
            [
                'id' => 3,
                'code' => 'C',
                'name' => 'Northwind Pantry',
                'slug' => 'northwind-pantry',
                'email' => 'northwind@demo.local',
                'password' => 'NorthwindDemo123!',
                'commission' => '8.00',
                'description' => 'Regional pantry for postcodes 10, 20 and 75. Shipping €7.00, free above €40.00.',
                'contact_name' => 'Elena Vogt',
                'phone' => '+49 351 000000',
                'shipping' => [
                    'base_cents' => 700,
                    'free_threshold_cents' => 4000,
                    'surcharge_cents_per_kg' => 0,
                    'postcode_prefixes' => ['10', '20', '75'],
                ],
            ],
        ];
    }

    /**
     * Extra rules sit beside the primary rule. They apply only where their postcode
     * prefix matches, so the verified 10115 scenario totals stay on the primary rule.
     *
     * @return list<array{vendor_code: string, name: string, base_cents: int, free_threshold_cents: int, surcharge_cents_per_kg: int, postcode_prefixes: list<string>}>
     */
    public static function extraShippingRules(): array
    {
        return [
            [
                'vendor_code' => 'B',
                'name' => 'Munich weight delivery',
                'base_cents' => 300,
                'free_threshold_cents' => 8000,
                'surcharge_cents_per_kg' => 150,
                'postcode_prefixes' => ['80'],
            ],
        ];
    }

    /**
     * @return list<array{
     *   id: int,
     *   sku: string,
     *   ean: string,
     *   name: string,
     *   brand: string,
     *   description: string,
     *   weight_grams: int,
     *   offers: list<array{id: int, vendor_id: int, list_cents: int, discount: string, stock: int}>
     * }>
     */
    public static function products(): array
    {
        return [
            [
                'id' => 1,
                'sku' => 'COFFEE-1KG',
                'ean' => '1234567890123',
                'name' => 'Premium Coffee 1kg',
                'brand' => 'Marketplace Roasters',
                'category' => 'Grocery',
                'description' => 'Whole-bean coffee, one kilogram. Three vendors sell the same lot, each with their own price and stock.',
                'weight_grams' => 1000,
                'offers' => [
                    ['id' => 1, 'vendor_id' => 1, 'list_cents' => 1200, 'discount' => '0.00', 'stock' => 5],
                    ['id' => 2, 'vendor_id' => 2, 'list_cents' => 1120, 'discount' => '0.00', 'stock' => 2],
                    ['id' => 3, 'vendor_id' => 3, 'list_cents' => 1250, 'discount' => '0.00', 'stock' => 20],
                ],
            ],
            [
                'id' => 2,
                'sku' => 'MUESLI-1KG',
                'ean' => '1234567890124',
                'name' => 'Alpine Muesli 1kg',
                'brand' => 'Breakfast Mill',
                'category' => 'Grocery',
                'description' => 'Toasted muesli, one kilogram. Often paired with honey; the delivered total depends on who ships both.',
                'weight_grams' => 1000,
                'offers' => [
                    ['id' => 4, 'vendor_id' => 1, 'list_cents' => 3600, 'discount' => '0.00', 'stock' => 8],
                    ['id' => 5, 'vendor_id' => 2, 'list_cents' => 3000, 'discount' => '0.00', 'stock' => 8],
                    ['id' => 6, 'vendor_id' => 3, 'list_cents' => 4100, 'discount' => '0.00', 'stock' => 8],
                ],
            ],
            [
                'id' => 3,
                'sku' => 'HONEY-500',
                'ean' => '1234567890125',
                'name' => 'Wildflower Honey 500g',
                'brand' => 'Meadow & Co',
                'category' => 'Grocery',
                'description' => 'Blossom honey, 500 grams, in a glass jar.',
                'weight_grams' => 500,
                'offers' => [
                    ['id' => 7, 'vendor_id' => 1, 'list_cents' => 1600, 'discount' => '0.00', 'stock' => 8],
                    ['id' => 8, 'vendor_id' => 2, 'list_cents' => 2200, 'discount' => '0.00', 'stock' => 8],
                    ['id' => 9, 'vendor_id' => 3, 'list_cents' => 1500, 'discount' => '0.00', 'stock' => 8],
                ],
            ],
            [
                'id' => 4,
                'sku' => 'OIL-500',
                'ean' => '1234567890126',
                'name' => 'Ligurian Olive Oil 500ml',
                'brand' => 'Costa Ligure',
                'category' => 'Grocery',
                'description' => 'Extra virgin olive oil, 500 millilitres. Harbor Bean lists it with a 10% discount.',
                'weight_grams' => 500,
                'offers' => [
                    ['id' => 10, 'vendor_id' => 1, 'list_cents' => 2400, 'discount' => '0.00', 'stock' => 10],
                    ['id' => 11, 'vendor_id' => 2, 'list_cents' => 2000, 'discount' => '10.00', 'stock' => 6],
                    ['id' => 12, 'vendor_id' => 3, 'list_cents' => 2200, 'discount' => '0.00', 'stock' => 12],
                ],
            ],
            [
                'id' => 5,
                'sku' => 'OATS-5KG',
                'ean' => '1234567890127',
                'name' => 'Bulk Oats 5kg',
                'brand' => 'Millhouse',
                'category' => 'Grocery',
                'description' => 'Rolled oats in a 5 kilogram sack. Larger quantities may ship from more than one vendor.',
                'weight_grams' => 5000,
                'offers' => [
                    ['id' => 13, 'vendor_id' => 1, 'list_cents' => 800, 'discount' => '0.00', 'stock' => 2],
                    ['id' => 14, 'vendor_id' => 2, 'list_cents' => 850, 'discount' => '0.00', 'stock' => 4],
                    ['id' => 15, 'vendor_id' => 3, 'list_cents' => 1300, 'discount' => '0.00', 'stock' => 3],
                ],
            ],
            self::extra('PASTA-500', '4000000000001', 'Bronze Penne 500g', 'Casa Grano', 'Pantry', 'Dried bronze-cut penne.', 500, 16, 240, 220, 260, 24, 18, 30),
            self::extra('TEA-100', '4000000000002', 'Green Tea 100g', 'Hill & Leaf', 'Drinks', 'Loose green tea in a tin.', 100, 19, 890, 950, 1100, 20, 14, 16),
            self::extra('WATER-6', '4000000000003', 'Sparkling Water 6×1L', 'Alta Fonte', 'Drinks', 'Six glass bottles of sparkling water.', 6000, 22, 420, 390, 450, 30, 22, 18),
            self::extra('SOAP-500', '4000000000004', 'Citrus Dish Soap 500ml', 'Clean Harbor', 'Home', 'Everyday dish soap.', 500, 25, 340, 299, 380, 40, 28, 24),
            self::extra('TOWEL-2', '4000000000005', 'Cotton Bath Towels', 'Linen House', 'Home', 'A pair of cotton bath towels.', 700, 28, 1800, 1650, 1990, 12, 10, 8),
            self::extra('MUG-01', '4000000000006', 'Stoneware Mug', 'Kiln & Co', 'Kitchen', 'A plain stoneware mug.', 350, 31, 1200, 1090, 1350, 16, 12, 20),
            self::extra('RICE-2KG', '4000000000007', 'Basmati Rice 2kg', 'River Mill', 'Pantry', 'Long-grain basmati rice.', 2000, 34, 640, 590, 720, 18, 20, 14),
            self::extra('CHOC-100', '4000000000008', 'Dark Chocolate 100g', 'Cacao Nord', 'Pantry', 'A plain dark chocolate bar.', 100, 37, 280, 250, 320, 40, 36, 22),
            self::extra('CREAM-75', '4000000000009', 'Hand Cream 75ml', 'Meadow & Co', 'Care', 'Unscented hand cream.', 75, 40, 890, 950, 790, 15, 12, 18),
        ];
    }

    /**
     * @return array{id: int, sku: string, ean: string, name: string, brand: string, category: string, description: string, weight_grams: int, offers: list<array{id: int, vendor_id: int, list_cents: int, discount: string, stock: int}>}
     */
    private static function extra(string $sku, string $ean, string $name, string $brand, string $category, string $description, int $grams, int $firstOfferId, int $alpineCents, int $harborCents, int $northCents, int $alpineStock, int $harborStock, int $northStock): array
    {
        return [
            'id' => $firstOfferId,
            'sku' => $sku,
            'ean' => $ean,
            'name' => $name,
            'brand' => $brand,
            'category' => $category,
            'description' => $description,
            'weight_grams' => $grams,
            'offers' => [
                ['id' => $firstOfferId, 'vendor_id' => 1, 'list_cents' => $alpineCents, 'discount' => '0.00', 'stock' => $alpineStock],
                ['id' => $firstOfferId + 1, 'vendor_id' => 2, 'list_cents' => $harborCents, 'discount' => '0.00', 'stock' => $harborStock],
                ['id' => $firstOfferId + 2, 'vendor_id' => 3, 'list_cents' => $northCents, 'discount' => '0.00', 'stock' => $northStock],
            ],
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function scenarioQuantities(): array
    {
        return [
            'coffee-split' => ['COFFEE-1KG' => 6],
            'breakfast-shipping' => ['MUESLI-1KG' => 1, 'HONEY-500' => 1],
            'oats-must-split' => ['OATS-5KG' => 6],
            'oats-no-stock' => ['OATS-5KG' => 30],
        ];
    }

    /**
     * @return list<array{name: string, slug: string, starts_at: ?string, ends_at: ?string, products: list<string>}>
     */
    public static function collections(): array
    {
        return [
            [
                'name' => 'Breakfast',
                'slug' => 'breakfast',
                'starts_at' => null,
                'ends_at' => null,
                'products' => ['COFFEE-1KG', 'MUESLI-1KG', 'HONEY-500', 'OATS-5KG'],
            ],
            [
                'name' => 'Aperitivo',
                'slug' => 'aperitivo',
                'starts_at' => null,
                'ends_at' => null,
                'products' => ['OIL-500'],
            ],
            [
                'name' => 'Christmas',
                'slug' => 'christmas',
                'starts_at' => '2026-12-01 00:00:00',
                'ends_at' => '2026-12-26 23:59:59',
                'products' => ['COFFEE-1KG', 'HONEY-500'],
            ],
            [
                'name' => 'Easter',
                'slug' => 'easter',
                'starts_at' => '2027-03-01 00:00:00',
                'ends_at' => '2027-04-30 23:59:59',
                'products' => ['OIL-500', 'HONEY-500'],
            ],
        ];
    }

    public static function vendorById(int $id): array
    {
        foreach (self::vendors() as $vendor) {
            if ($vendor['id'] === $id) {
                return $vendor;
            }
        }

        throw new \InvalidArgumentException('Unknown demo vendor ' . $id);
    }

    public static function productBySku(string $sku): array
    {
        foreach (self::products() as $product) {
            if ($product['sku'] === $sku) {
                return $product;
            }
        }

        throw new \InvalidArgumentException('Unknown demo SKU ' . $sku);
    }
}
