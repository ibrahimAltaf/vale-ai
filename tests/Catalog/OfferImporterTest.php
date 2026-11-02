<?php

declare(strict_types=1);

namespace Marketplace\Tests\Catalog;

use Marketplace\Catalog\Dto\CatalogProduct;
use Marketplace\Catalog\Dto\ImportAction;
use Marketplace\Catalog\Service\CsvOfferParser;
use Marketplace\Catalog\Service\OfferImporter;
use Marketplace\Catalog\Service\ProductMatcher;
use PHPUnit\Framework\TestCase;

final class OfferImporterTest extends TestCase
{
    private const HEADER = "vendor,sku,ean,brand,title,description,price,discount,stock,weight,image_url\n";

    public function testUpdateExistingOfferByVendorAndSku(): void
    {
        $rows = (new CsvOfferParser())->parse(self::HEADER . "A,COFFEE-1KG,1234567890123,Alpine,Premium Coffee 1kg,Updated,11.90,0,8,1,\n");
        $plan = $this->importer()->plan(
            $rows,
            [new CatalogProduct(1, 'COFFEE-1KG', '1234567890123', 'Premium Coffee 1kg')],
            [['id' => 41, 'vendor_code' => 'A', 'sku' => 'COFFEE-1KG', 'ean' => '1234567890123']],
            ['A', 'B', 'C'],
        );

        self::assertSame(1, $plan->count(ImportAction::UPDATE));
        self::assertSame(41, $plan->actions[0]->existingOfferId);
        self::assertSame(1190, $plan->actions[0]->row?->listPriceCents);
        self::assertSame(8, $plan->actions[0]->row?->stock);
    }

    public function testUpdateExistingOfferByVendorAndEanWhenSkuChanged(): void
    {
        $rows = (new CsvOfferParser())->parse(self::HEADER . "B,COFFEE-NEW,1234567890123,Harbor,Premium Coffee 1kg,Same EAN,11.20,0,2,1,\n");
        $plan = $this->importer()->plan(
            $rows,
            [new CatalogProduct(1, 'COFFEE-1KG', '1234567890123', 'Premium Coffee 1kg')],
            [['id' => 42, 'vendor_code' => 'B', 'sku' => 'COFFEE-1KG', 'ean' => '1234567890123']],
            ['A', 'B', 'C'],
        );

        self::assertSame(ImportAction::UPDATE, $plan->actions[0]->type);
        self::assertSame(42, $plan->actions[0]->existingOfferId);
    }

    public function testAmbiguousRowIsRejectedAndNotMerged(): void
    {
        $rows = (new CsvOfferParser())->parse(self::HEADER . "A,AMBIGUOUS,1234567890123,Brand,Title,Desc,5.00,0,1,0.2,\n");
        $plan = $this->importer()->plan(
            $rows,
            [
                new CatalogProduct(1, 'COFFEE-1KG', '1234567890123', 'Premium Coffee 1kg'),
                new CatalogProduct(9, 'COFFEE-ALT', '1234567890123', 'Other coffee'),
            ],
            [],
            ['A'],
        );

        self::assertSame(ImportAction::REJECT, $plan->actions[0]->type);
        self::assertSame('AMBIGUOUS_MATCH', $plan->actions[0]->match?->type->value);
        self::assertNull($plan->actions[0]->productId);
    }

    public function testNoMatchPlansANewMasterProduct(): void
    {
        $rows = (new CsvOfferParser())->parse(self::HEADER . "C,TEA-100,5901234123457,Northwind,Sample Tea,New master,4.50,0,15,0.1,https://example.com/tea.jpg\n");
        $plan = $this->importer()->plan($rows, [], [], ['C']);

        self::assertSame(ImportAction::CREATE_MASTER, $plan->actions[0]->type);
        self::assertSame('NO_MATCH', $plan->actions[0]->match?->type->value);
        self::assertSame(100, $plan->actions[0]->row?->weightGrams);
    }

    public function testExactEanPlansAnOfferOnTheMasterProduct(): void
    {
        $rows = (new CsvOfferParser())->parse(self::HEADER . "C,COFFEE-1KG,1234567890123,Northwind,Premium Coffee 1kg,Matched,12.50,0,20,1,\n");
        $plan = $this->importer()->plan(
            $rows,
            [new CatalogProduct(1, 'COFFEE-1KG', '1234567890123', 'Premium Coffee 1kg')],
            [],
            ['C'],
        );

        self::assertSame(ImportAction::CREATE_OFFER, $plan->actions[0]->type);
        self::assertSame(1, $plan->actions[0]->productId);
        self::assertSame('EXACT_MATCH', $plan->actions[0]->match?->type->value);
    }

    private function importer(): OfferImporter
    {
        return new OfferImporter(new ProductMatcher());
    }
}
