<?php

declare(strict_types=1);

namespace Marketplace\Orders\Service;

/**
 * Checks a card before the shop records the payment.
 * The full number and the security code are not returned or stored.
 */
final class CardPayment
{
    /**
     * @return array{last4: string, brand: string, expiry: string, holder: string}
     */
    public function authorize(string $pan, string $expiry, string $cvc, string $holder): array
    {
        $digits = preg_replace('/\D+/', '', $pan) ?? '';
        if (strlen($digits) < 13 || strlen($digits) > 19 || !$this->luhn($digits)) {
            throw new \InvalidArgumentException('The card number is not valid.');
        }
        if (!preg_match('/^\d{3,4}$/', trim($cvc))) {
            throw new \InvalidArgumentException('The security code must be 3 or 4 digits.');
        }
        $holder = trim($holder);
        if (strlen($holder) < 2 || strlen($holder) > 64) {
            throw new \InvalidArgumentException('Enter the name on the card.');
        }
        if (!preg_match('/^(0[1-9]|1[0-2])\/(\d{2}|\d{4})$/', trim($expiry), $match)) {
            throw new \InvalidArgumentException('Enter the expiry as MM/YY.');
        }
        $month = (int) $match[1];
        $year = strlen($match[2]) === 2 ? 2000 + (int) $match[2] : (int) $match[2];
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('last day of this month')->setTime(23, 59, 59);
        if ($end < new \DateTimeImmutable('now')) {
            throw new \InvalidArgumentException('This card has expired.');
        }

        return [
            'last4' => substr($digits, -4),
            'brand' => $this->brand($digits),
            'expiry' => sprintf('%02d/%02d', $month, $year % 100),
            'holder' => $holder,
        ];
    }

    private function luhn(string $digits): bool
    {
        $sum = 0;
        $alternate = false;
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];
            if ($alternate) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $alternate = !$alternate;
        }

        return $sum % 10 === 0;
    }

    private function brand(string $digits): string
    {
        return match ($digits[0]) {
            '4' => 'Visa',
            '5' => 'Mastercard',
            '3' => 'American Express',
            default => 'Card',
        };
    }
}
