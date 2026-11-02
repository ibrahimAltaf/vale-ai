<?php

declare(strict_types=1);

namespace Marketplace\Core\Install;

use Marketplace\Core\Database\Connection;
use Marketplace\Core\Repository\CatalogRepository;
use Marketplace\Core\Repository\CollectionRepository;
use Marketplace\Core\Repository\VendorRepository;
use Marketplace\Core\Seed\DemoCatalog;

final class DemoSeeder
{
    public function seed(): void
    {
        $db = new Connection();
        $vendors = new VendorRepository($db);
        $catalog = new CatalogRepository($db);
        $collections = new CollectionRepository($db);
        $products = new PrestaShopProductGateway();
        $vendorIds = [];

        foreach (DemoCatalog::vendors() as $vendor) {
            $id = $vendors->saveProfile(
                $vendor['code'],
                $vendor['name'],
                $vendor['slug'],
                $vendor['email'],
                password_hash($vendor['password'], PASSWORD_DEFAULT),
                $vendor['commission'],
                $vendor['description'],
                $vendor['contact_name'],
                $vendor['phone'],
            );
            $vendorIds[$vendor['id']] = $id;
            $shipping = $vendor['shipping'];
            $vendors->saveShipping(
                $id,
                $vendor['name'] . ' shipping',
                $shipping['base_cents'],
                $shipping['free_threshold_cents'],
                $shipping['surcharge_cents_per_kg'],
                implode(',', $shipping['postcode_prefixes']),
            );
        }

        $productIds = [];
        foreach (DemoCatalog::products() as $product) {
            $lowest = null;
            foreach ($product['offers'] as $offer) {
                $net = \Marketplace\Core\Domain\Money::afterDiscount($offer['list_cents'], $offer['discount']);
                $lowest = $lowest === null ? $net : min($lowest, $net);
            }
            $productId = $products->upsert(
                $product['sku'],
                $product['ean'],
                $product['name'],
                $product['description'],
                $lowest ?? 0,
                (string) ($product['category'] ?? 'Grocery'),
            );
            $productIds[$product['sku']] = $productId;
            foreach ($product['offers'] as $offer) {
                $catalog->upsertOffer(
                    $vendorIds[$offer['vendor_id']],
                    $productId,
                    $product['sku'],
                    $product['ean'],
                    $product['brand'],
                    $product['name'],
                    $product['description'],
                    $offer['list_cents'],
                    $offer['discount'],
                    $product['weight_grams'],
                    '',
                    $offer['stock'],
                    true,
                );
            }
        }

        foreach (DemoCatalog::collections() as $collection) {
            $ids = [];
            foreach ($collection['products'] as $sku) {
                if (isset($productIds[$sku])) {
                    $ids[] = $productIds[$sku];
                }
            }
            $collections->upsert($collection['name'], $collection['slug'], $collection['starts_at'], $collection['ends_at'], $ids);
        }
    }
}
