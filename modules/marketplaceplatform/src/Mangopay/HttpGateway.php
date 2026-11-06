<?php

declare(strict_types=1);

namespace Marketplace\Platform\Mangopay;

use Marketplace\Platform\PlatformException;

/**
 * Mangopay REST client. Sandbox is the default host.
 * A successful response from this class is still not treated as production verification
 * unless the call was made with real sandbox credentials and recorded as such.
 */
final class HttpGateway implements Gateway
{
    private string $token = '';

    private int $tokenExpires = 0;

    public function __construct(
        private readonly string $clientId,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $platformWallet,
        private readonly HttpTransport $transport,
    ) {
    }

    public static function fromEnvironment(?HttpTransport $transport = null): Gateway
    {
        $client = getenv('MARKETPLACE_MANGOPAY_CLIENT_ID');
        $key = getenv('MARKETPLACE_MANGOPAY_API_KEY');
        if (!is_string($client) || $client === '' || !is_string($key) || $key === '') {
            return new UnavailableGateway();
        }
        $base = getenv('MARKETPLACE_MANGOPAY_BASE_URL');
        $wallet = getenv('MARKETPLACE_MANGOPAY_PLATFORM_WALLET');

        return new self(
            $client,
            $key,
            rtrim(is_string($base) && $base !== '' ? $base : 'https://api.sandbox.mangopay.com', '/'),
            is_string($wallet) ? $wallet : '',
            $transport ?? new CurlTransport(),
        );
    }

    public function mode(): string
    {
        return 'sandbox';
    }

    public function platformWalletId(): string
    {
        if ($this->platformWallet === '') {
            throw new PlatformException('mangopay_not_configured', 'MARKETPLACE_MANGOPAY_PLATFORM_WALLET is not configured.', 503);
        }

        return $this->platformWallet;
    }

    public function createUser(array $user): array
    {
        $legal = ($user['LegalPersonType'] ?? '') === 'BUSINESS';
        $path = $legal ? '/users/legal' : '/users/natural';

        return $this->request('POST', $path, $user);
    }

    public function createWallet(string $userId, string $currency): array
    {
        return $this->request('POST', '/wallets', [
            'Owners' => [$userId],
            'Description' => 'Vendor wallet',
            'Currency' => $currency,
        ]);
    }

    public function createPayIn(array $payIn): array
    {
        CardPayload::reject($payIn);
        $key = (string) ($payIn['IdempotencyKey'] ?? '');
        unset($payIn['IdempotencyKey']);

        return $this->request('POST', '/payins/card/direct', $payIn, $key);
    }

    public function createTransfer(array $transfer): array
    {
        return $this->request('POST', '/transfers', $transfer);
    }

    public function createRefund(string $payInId, int $amountCents, string $idempotencyKey): array
    {
        return $this->request('POST', '/payins/' . rawurlencode($payInId) . '/refunds', [
            'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => $amountCents],
            'Fees' => ['Currency' => 'EUR', 'Amount' => 0],
        ], $idempotencyKey);
    }

    public function createPayout(array $payout): array
    {
        $bankAccount = getenv('MARKETPLACE_MANGOPAY_BANK_ACCOUNT');
        if (!is_string($bankAccount) || $bankAccount === '') {
            throw new PlatformException('mangopay_not_configured', 'MARKETPLACE_MANGOPAY_BANK_ACCOUNT is not configured.', 503);
        }
        $payout['BankAccountId'] = $bankAccount;
        $payout['BankWireRef'] = 'MARKETPLACE';

        return $this->request('POST', '/payouts/bankwire', $payout);
    }

    public function submitKyc(string $userId): array
    {
        $document = $this->request('POST', '/users/' . rawurlencode($userId) . '/kyc/documents', [
            'Type' => 'IDENTITY_PROOF',
        ]);

        return [
            'Id' => $userId,
            'KYCLevel' => 'LIGHT',
            'DocumentId' => (string) ($document['Id'] ?? ''),
            'Status' => 'SUBMITTED',
        ];
    }

    public function fetch(string $eventType, string $resourceId): ?array
    {
        $path = match (true) {
            str_starts_with($eventType, 'PAYIN') => '/payins/' . rawurlencode($resourceId),
            str_starts_with($eventType, 'PAYOUT') => '/payouts/' . rawurlencode($resourceId),
            str_contains($eventType, 'REFUND') => '/refunds/' . rawurlencode($resourceId),
            default => null,
        };
        if ($path === null) {
            return null;
        }
        try {
            return $this->request('GET', $path, null);
        } catch (PlatformException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, string $idempotencyKey = ''): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken(),
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . substr($idempotencyKey, 0, 36);
        }
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $response = $this->transport->send(
            $method,
            $this->baseUrl . '/v2.01/' . rawurlencode($this->clientId) . $path,
            $headers,
            $payload,
        );
        $decoded = json_decode($response['body'], true);
        if ($response['status'] >= 400) {
            $message = 'The payment provider rejected the request.';
            if (is_array($decoded)) {
                $message = (string) ($decoded['Message'] ?? $decoded['error'] ?? $message);
            }
            throw new PlatformException('mangopay_rejected', $message, 502);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function accessToken(): string
    {
        if ($this->token !== '' && $this->tokenExpires > time() + 30) {
            return $this->token;
        }
        $response = $this->transport->send(
            'POST',
            $this->baseUrl . '/v2.01/' . rawurlencode($this->clientId) . '/oauth/token',
            [
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->apiKey),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            'grant_type=client_credentials',
        );
        $decoded = json_decode($response['body'], true);
        if ($response['status'] >= 400 || !is_array($decoded) || !isset($decoded['access_token'])) {
            throw new PlatformException('mangopay_unavailable', 'Mangopay authentication failed.', 502);
        }
        $this->token = (string) $decoded['access_token'];
        $this->tokenExpires = time() + (int) ($decoded['expires_in'] ?? 3600);

        return $this->token;
    }
}
