<?php

declare(strict_types=1);

namespace Marketplace\Platform\Mangopay;

use Marketplace\Platform\PlatformException;

/**
 * Used when sandbox credentials are absent. Calls fail closed.
 */
final class UnavailableGateway implements Gateway
{
    public function mode(): string
    {
        return 'unconfigured';
    }

    public function platformWalletId(): string
    {
        $this->blocked();
    }

    public function createUser(array $user): array
    {
        $this->blocked();
    }

    public function createWallet(string $userId, string $currency): array
    {
        $this->blocked();
    }

    public function createPayIn(array $payIn): array
    {
        $this->blocked();
    }

    public function createTransfer(array $transfer): array
    {
        $this->blocked();
    }

    public function createRefund(string $payInId, int $amountCents, string $idempotencyKey): array
    {
        $this->blocked();
    }

    public function createPayout(array $payout): array
    {
        $this->blocked();
    }

    public function submitKyc(string $userId): array
    {
        $this->blocked();
    }

    public function fetch(string $eventType, string $resourceId): ?array
    {
        return null;
    }

    /**
     * @return never
     */
    private function blocked(): void
    {
        throw new PlatformException(
            'mangopay_not_configured',
            'Mangopay sandbox credentials are not configured. Live payment verification is blocked.',
            503,
        );
    }
}
