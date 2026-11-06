<?php

declare(strict_types=1);

namespace Marketplace\Platform\Mangopay;

use Marketplace\Platform\PlatformException;

/**
 * In-process provider used by tests and local development without credentials.
 * It is not evidence that a live Mangopay payment succeeded.
 */
final class MemoryGateway implements Gateway
{
    /** @var array<string, array<string, mixed>> */
    private array $resources = [];

    public bool $failTransfers = false;

    public function mode(): string
    {
        return 'memory';
    }

    public function platformWalletId(): string
    {
        return 'platform_wallet';
    }

    public function createUser(array $user): array
    {
        $id = 'user_' . bin2hex(random_bytes(4));
        $this->resources[$id] = [
            'Id' => $id,
            'Type' => 'USER',
            'KYCLevel' => 'LIGHT',
            'Email' => (string) ($user['Email'] ?? ''),
        ];

        return $this->resources[$id];
    }

    public function createWallet(string $userId, string $currency): array
    {
        $id = 'wallet_' . bin2hex(random_bytes(4));
        $this->resources[$id] = [
            'Id' => $id,
            'Type' => 'WALLET',
            'Owners' => [$userId],
            'Currency' => $currency,
            'Balance' => ['Amount' => 0, 'Currency' => $currency],
        ];

        return $this->resources[$id];
    }

    public function createPayIn(array $payIn): array
    {
        CardPayload::reject($payIn);
        $cardId = (string) ($payIn['CardId'] ?? '');
        if ($cardId === '') {
            throw new PlatformException('validation_error', 'A Mangopay CardId is required.', 422);
        }
        $status = match ($cardId) {
            'card_fail' => 'FAILED',
            'card_pending' => 'CREATED',
            default => 'SUCCEEDED',
        };
        $id = 'payin_' . bin2hex(random_bytes(6));
        $this->resources[$id] = [
            'Id' => $id,
            'Type' => 'PAYIN',
            'Status' => $status,
            'DebitedFunds' => $payIn['DebitedFunds'] ?? ['Currency' => 'EUR', 'Amount' => 0],
            'CardId' => $cardId,
        ];

        return $this->resources[$id];
    }

    public function createTransfer(array $transfer): array
    {
        if ($this->failTransfers) {
            throw new PlatformException('transfer_failed', 'Wallet transfer failed.', 502);
        }
        $id = 'xfer_' . bin2hex(random_bytes(4));
        $this->resources[$id] = [
            'Id' => $id,
            'Type' => 'TRANSFER',
            'Status' => 'SUCCEEDED',
            'DebitedFunds' => $transfer['DebitedFunds'] ?? ['Currency' => 'EUR', 'Amount' => 0],
        ];

        return $this->resources[$id];
    }

    public function createRefund(string $payInId, int $amountCents, string $idempotencyKey): array
    {
        if (!isset($this->resources[$payInId])) {
            throw new PlatformException('refund_failed', 'The provider payment was not found.', 404);
        }
        $id = 'refund_' . bin2hex(random_bytes(4));
        $this->resources[$id] = [
            'Id' => $id,
            'Type' => 'REFUND',
            'Status' => 'SUCCEEDED',
            'InitialTransactionId' => $payInId,
            'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => $amountCents],
        ];

        return $this->resources[$id];
    }

    public function createPayout(array $payout): array
    {
        $id = 'payout_' . bin2hex(random_bytes(4));
        $this->resources[$id] = [
            'Id' => $id,
            'Type' => 'PAYOUT',
            'Status' => 'SUCCEEDED',
            'DebitedFunds' => $payout['DebitedFunds'] ?? ['Currency' => 'EUR', 'Amount' => 0],
        ];

        return $this->resources[$id];
    }

    public function submitKyc(string $userId): array
    {
        if (!isset($this->resources[$userId])) {
            throw new PlatformException('kyc_failed', 'The provider user was not found.', 404);
        }
        $this->resources[$userId]['KYCLevel'] = 'REGULAR';

        return $this->resources[$userId];
    }

    public function fetch(string $eventType, string $resourceId): ?array
    {
        $resource = $this->resources[$resourceId] ?? null;
        if ($resource === null) {
            return null;
        }
        $expected = match (true) {
            str_starts_with($eventType, 'PAYIN') => 'PAYIN',
            str_starts_with($eventType, 'PAYOUT') => 'PAYOUT',
            str_starts_with($eventType, 'TRANSFER') => 'TRANSFER',
            str_contains($eventType, 'REFUND') => 'REFUND',
            default => '',
        };
        if ($expected !== '' && ($resource['Type'] ?? '') !== $expected) {
            return null;
        }

        return $resource;
    }

    /**
     * Test helper. Lets a webhook observe a later provider status.
     *
     * @param array<string, mixed> $values
     */
    public function update(string $resourceId, array $values): void
    {
        if (!isset($this->resources[$resourceId])) {
            throw new PlatformException('not_found', 'The provider resource was not found.', 404);
        }
        $this->resources[$resourceId] = array_merge($this->resources[$resourceId], $values);
    }
}
