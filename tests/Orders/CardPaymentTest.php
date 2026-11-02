<?php

declare(strict_types=1);

namespace Marketplace\Tests\Orders;

use Marketplace\Orders\Service\CardPayment;
use PHPUnit\Framework\TestCase;

final class CardPaymentTest extends TestCase
{
    public function testValidCardReturnsOnlyTheLastFourDigits(): void
    {
        $result = (new CardPayment())->authorize('4242 4242 4242 4242', '12/40', '123', 'Ada Lovelace');

        self::assertSame('4242', $result['last4']);
        self::assertSame('Visa', $result['brand']);
        self::assertSame('12/40', $result['expiry']);
        self::assertSame('Ada Lovelace', $result['holder']);
        self::assertArrayNotHasKey('pan', $result);
    }

    public function testRejectedCardNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CardPayment())->authorize('4242424242424241', '12/40', '123', 'Ada Lovelace');
    }

    public function testExpiredCard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CardPayment())->authorize('4242424242424242', '01/20', '123', 'Ada Lovelace');
    }
}
