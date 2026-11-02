<?php

declare(strict_types=1);

namespace Marketplace\Catalog\Service;

use Marketplace\Catalog\Dto\ImportRow;
use Marketplace\Core\Domain\Money;

final class CsvOfferParser
{
    public const HEADER = [
        'vendor',
        'sku',
        'ean',
        'brand',
        'title',
        'description',
        'price',
        'discount',
        'stock',
        'weight',
        'image_url',
    ];

    /**
     * @return list<ImportRow>
     */
    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $handle = fopen('php://temp', 'rb+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read CSV.');
        }

        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            throw new \InvalidArgumentException('CSV is empty.');
        }

        $header = array_map(static fn ($column): string => strtolower(trim((string) $column)), $header);
        if ($header !== self::HEADER) {
            fclose($handle);
            throw new \InvalidArgumentException('CSV header must be: ' . implode(',', self::HEADER));
        }

        $rows = [];
        $lineNumber = 1;
        while (($data = fgetcsv($handle)) !== false) {
            ++$lineNumber;
            if ($data === [null] || (count($data) === 1 && trim((string) $data[0]) === '')) {
                continue;
            }
            $data = array_pad($data, count(self::HEADER), '');
            $column = static fn (int $index) => trim((string) $data[$index]);

            $sku = $column(1);
            $vendor = strtoupper($column(0));
            if ($vendor === '' || $sku === '') {
                throw new \InvalidArgumentException(sprintf('Line %d is missing vendor or SKU.', $lineNumber));
            }

            $stock = $column(8);
            if (!preg_match('/^\d+$/', $stock)) {
                throw new \InvalidArgumentException(sprintf('Line %d has an invalid stock quantity.', $lineNumber));
            }

            $rows[] = new ImportRow(
                $lineNumber,
                $vendor,
                $sku,
                $column(2),
                $column(3),
                $column(4),
                $column(5),
                Money::fromDecimal($column(6)),
                $column(7) === '' ? '0' : $column(7),
                (int) $stock,
                Money::kilogramsToGrams($column(9)),
                $column(10),
            );
        }

        fclose($handle);

        return $rows;
    }
}
