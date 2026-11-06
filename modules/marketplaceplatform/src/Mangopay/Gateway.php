<?php

declare(strict_types=1);

namespace Marketplace\Platform\Mangopay;

/**
 * Payment provider boundary. Card numbers and CVV values never cross this contract.
 */
interface Gateway
{
    public function mode(): string;

    public function platformWalletId(): string;

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function createUser(array $user): array;

    /**
     * @return array<string, mixed>
     */
    public function createWallet(string $userId, string $currency): array;

    /**
     * @param array<string, mixed> $payIn
     * @return array<string, mixed>
     */
    public function createPayIn(array $payIn): array;

    /**
     * @param array<string, mixed> $transfer
     * @return array<string, mixed>
     */
    public function createTransfer(array $transfer): array;

    /**
     * @return array<string, mixed>
     */
    public function createRefund(string $payInId, int $amountCents, string $idempotencyKey): array;

    /**
     * @param array<string, mixed> $payout
     * @return array<string, mixed>
     */
    public function createPayout(array $payout): array;

    /**
     * @return array<string, mixed>
     */
    public function submitKyc(string $userId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(string $eventType, string $resourceId): ?array;
}
