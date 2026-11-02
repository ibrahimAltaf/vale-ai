<?php

declare(strict_types=1);

namespace Marketplace\Catalog\Service;

use Marketplace\Catalog\Dto\CatalogProduct;
use Marketplace\Catalog\Dto\ImportAction;
use Marketplace\Catalog\Dto\ImportPlan;
use Marketplace\Catalog\Dto\ImportRow;
use Marketplace\Catalog\Dto\MatchType;

/**
 * Plans offer creates and updates. Persistence is intentionally outside this class
 * so a cron worker can execute the same plan asynchronously.
 */
final class OfferImporter
{
    public function __construct(private readonly ProductMatcher $matcher)
    {
    }

    /**
     * @param list<ImportRow> $rows
     * @param list<CatalogProduct> $catalog
     * @param list<array{id: int, vendor_code: string, sku: string, ean: string}> $existingOffers
     * @param list<string> $vendorCodes
     */
    public function plan(array $rows, array $catalog, array $existingOffers, array $vendorCodes): ImportPlan
    {
        $knownVendors = [];
        foreach ($vendorCodes as $code) {
            $knownVendors[strtoupper($code)] = true;
        }

        $actions = [];
        foreach ($rows as $row) {
            if (!isset($knownVendors[$row->vendorCode])) {
                $actions[] = new ImportAction(ImportAction::REJECT, $row->lineNumber, $row->vendorCode, $row->sku, 'Unknown vendor.', row: $row);
                continue;
            }

            $existing = $this->findExisting($existingOffers, $row);
            if ($existing !== null) {
                $actions[] = new ImportAction(
                    ImportAction::UPDATE,
                    $row->lineNumber,
                    $row->vendorCode,
                    $row->sku,
                    'Update the existing vendor offer.',
                    existingOfferId: $existing['id'],
                    row: $row,
                );
                continue;
            }

            $match = $this->matcher->match($row->ean, $row->sku, $catalog);
            if ($match->type === MatchType::Ambiguous) {
                $actions[] = new ImportAction(
                    ImportAction::REJECT,
                    $row->lineNumber,
                    $row->vendorCode,
                    $row->sku,
                    'Ambiguous catalog match. The row was not merged.',
                    row: $row,
                    match: $match,
                );
                continue;
            }

            if ($match->type === MatchType::Exact) {
                $actions[] = new ImportAction(
                    ImportAction::CREATE_OFFER,
                    $row->lineNumber,
                    $row->vendorCode,
                    $row->sku,
                    'Create an offer on the matched master product.',
                    productId: $match->candidateProductIds[0],
                    row: $row,
                    match: $match,
                );
                continue;
            }

            $actions[] = new ImportAction(
                ImportAction::CREATE_MASTER,
                $row->lineNumber,
                $row->vendorCode,
                $row->sku,
                'No catalog match. Create a new master product and its first offer.',
                row: $row,
                match: $match,
            );
        }

        return new ImportPlan($actions);
    }

    /**
     * @param list<array{id: int, vendor_code: string, sku: string, ean: string}> $existingOffers
     * @return array{id: int, vendor_code: string, sku: string, ean: string}|null
     */
    private function findExisting(array $existingOffers, ImportRow $row): ?array
    {
        foreach ($existingOffers as $offer) {
            if (strtoupper($offer['vendor_code']) === $row->vendorCode && strtoupper($offer['sku']) === strtoupper($row->sku)) {
                return $offer;
            }
        }

        if ($row->ean !== '') {
            foreach ($existingOffers as $offer) {
                if (strtoupper($offer['vendor_code']) === $row->vendorCode && strtoupper($offer['ean']) === strtoupper($row->ean)) {
                    return $offer;
                }
            }
        }

        return null;
    }
}
