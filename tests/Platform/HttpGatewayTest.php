<?php

declare(strict_types=1);

namespace Marketplace\Tests\Platform;

use Marketplace\Platform\Mangopay\HttpGateway;
use Marketplace\Platform\Mangopay\HttpTransport;
use PHPUnit\Framework\TestCase;

final class HttpGatewayTest extends TestCase
{
    public function testPayInSendsACardIdAndNoRawCardFields(): void
    {
        $transport = new RecordingTransport([
            ['status' => 200, 'body' => '{"access_token":"token-1","expires_in":3600}'],
            ['status' => 200, 'body' => '{"Id":"payin_1","Status":"SUCCEEDED"}'],
        ]);
        $gateway = new HttpGateway('client-id', 'api-key', 'https://api.sandbox.mangopay.com', 'wallet_platform', $transport);
        $gateway->createPayIn([
            'AuthorId' => 'user_1',
            'CreditedWalletId' => 'wallet_platform',
            'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => 1000],
            'Fees' => ['Currency' => 'EUR', 'Amount' => 0],
            'CardId' => 'card_test_ok',
            'SecureModeReturnURL' => 'https://shop.example/return',
            'IdempotencyKey' => 'idem-12345678',
        ]);

        $payin = $transport->calls[1];
        self::assertSame('POST', $payin['method']);
        self::assertStringContainsString('/payins/card/direct', $payin['url']);
        $body = (string) $payin['body'];
        self::assertStringContainsString('"CardId":"card_test_ok"', $body);
        self::assertStringNotContainsString('cardnumber', strtolower($body));
        self::assertStringNotContainsString('"cvv"', strtolower($body));
        self::assertStringNotContainsString('"cvc"', strtolower($body));
        $joined = implode("\n", $payin['headers']);
        self::assertStringContainsString('Authorization: Bearer token-1', $joined);
        self::assertStringContainsString('Idempotency-Key: idem-12345678', $joined);
        self::assertStringNotContainsString('api-key', $joined);
    }
}

final class RecordingTransport implements HttpTransport
{
    /** @var list<array{status: int, body: string}> */
    private array $responses;

    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    public array $calls = [];

    /**
     * @param list<array{status: int, body: string}> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return array_shift($this->responses) ?? ['status' => 500, 'body' => '{}'];
    }
}
