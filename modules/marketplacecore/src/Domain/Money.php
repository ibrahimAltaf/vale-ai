<?php

declare(strict_types=1);

namespace Marketplace\Core\Domain;

/**
 * Integer-cent money helpers. Marketplace calculations never use binary floats.
 */
final class Money
{
    public static function fromDecimal(string $amount): int
    {
        $amount = trim($amount);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException('Money amounts must be a non-negative decimal with up to 2 places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ((int) $whole) * 100 + (int) $fraction;
    }

    public static function percentOf(int $amountCents, string $percent): int
    {
        return intdiv($amountCents * self::basisPoints($percent) + 5000, 10000);
    }

    public static function afterDiscount(int $listCents, string $discountPercent): int
    {
        return $listCents - self::percentOf($listCents, $discountPercent);
    }

    public static function basisPoints(string $percent): int
    {
        $percent = trim($percent);
        if ($percent === '') {
            $percent = '0';
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $percent)) {
            throw new \InvalidArgumentException('Percent must be a non-negative decimal with up to 2 places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $basisPoints = ((int) $whole) * 100 + (int) $fraction;
        if ($basisPoints > 10000) {
            throw new \InvalidArgumentException('Percent cannot exceed 100.');
        }

        return $basisPoints;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function euros(int $cents): string
    {
        return '€' . self::format($cents);
    }

    public static function kilogramsToGrams(string $kilograms): int
    {
        $kilograms = trim($kilograms);
        if ($kilograms === '') {
            return 0;
        }
        if (!preg_match('/^\d+(\.\d{1,3})?$/', $kilograms)) {
            throw new \InvalidArgumentException('Weight must be a non-negative kilogram value with up to 3 decimals.');
        }

        [$whole, $fraction] = array_pad(explode('.', $kilograms, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');

        return ((int) $whole) * 1000 + (int) $fraction;
    }
}
