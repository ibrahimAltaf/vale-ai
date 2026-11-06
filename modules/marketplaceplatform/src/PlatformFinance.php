<?php

declare(strict_types=1);

namespace Marketplace\Platform;

use Marketplace\Core\Domain\Money;
use Marketplace\Platform\Mangopay\CardPayload;

/**
 * Mangopay payments, wallet settlement, refunds, payouts and webhook recovery.
 */
trait PlatformFinance
{
    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function pay(array $actor, int $orderId, string $cardId, string $returnUrl, string $idempotencyKey): array
    {
        $this->assertShopper($actor);
        CardPayload::reject(['CardId' => $cardId]);
        $cardId = trim($cardId);
        if ($cardId === '' || strlen($cardId) > 64) {
            throw new PlatformException('validation_error', 'A tokenized CardId is required.', 422);
        }
        if (!preg_match('#^https://#', $returnUrl)) {
            throw new PlatformException('validation_error', 'The return URL must be HTTPS.', 422);
        }
        $this->idempotencyKey($idempotencyKey);
        $order = $this->requireOrder($orderId);
        if ((int) $order['id_marketplace_shopper'] !== (int) $actor['id']) {
            throw new PlatformException('not_found', 'The order was not found.', 404);
        }
        if ((string) $order['status'] === 'cancelled') {
            throw new PlatformException('conflict', 'The order is cancelled.', 409);
        }
        if (in_array((string) $order['payment_status'], ['paid', 'partially_refunded', 'refunded'], true)) {
            throw new PlatformException('conflict', 'The order is already paid.', 409);
        }
        $this->ensureReservations($orderId);

        $existing = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_payment') . ' WHERE idempotency_key = :key',
            ['key' => $idempotencyKey]
        );
        if ($existing !== null) {
            $http = match ((string) $existing['status']) {
                'succeeded' => 200,
                'failed' => 402,
                default => 202,
            };

            return $this->paymentResult($existing, $http);
        }

        $shopper = $this->requireShopper((int) $actor['id']);
        $authorId = $this->ensureShopperPayer($shopper);
        $vendorOrders = $this->vendorOrders($orderId);
        foreach ($vendorOrders as $vendorOrder) {
            $this->requireWallet((int) $vendorOrder['id_marketplace_vendor']);
        }

        $now = $this->store->now();
        $paymentId = $this->store->insert('marketplace_payment', [
            'id_marketplace_order' => $orderId,
            'id_marketplace_shopper' => (int) $actor['id'],
            'provider' => 'mangopay',
            'provider_id' => null,
            'idempotency_key' => $idempotencyKey,
            'status' => 'pending',
            'settlement_status' => 'not_started',
            'amount_cents' => (int) $order['grand_cents'],
            'commission_cents' => 0,
            'currency' => 'EUR',
            'card_alias' => $this->cardAlias($cardId),
            'provider_status' => null,
            'date_add' => $now,
            'date_upd' => $now,
        ]);

        try {
            $response = $this->gateway->createPayIn([
                'AuthorId' => $authorId,
                'CreditedWalletId' => $this->gateway->platformWalletId(),
                'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => (int) $order['grand_cents']],
                'Fees' => ['Currency' => 'EUR', 'Amount' => 0],
                'CardId' => $cardId,
                'SecureModeReturnURL' => $returnUrl,
                'IdempotencyKey' => $idempotencyKey,
                'Tag' => 'order:' . $orderId,
            ]);
        } catch (PlatformException $exception) {
            $this->markPayment($paymentId, 'failed', null, 'ERROR');
            throw $exception;
        }

        $providerId = (string) ($response['Id'] ?? '');
        $providerStatus = (string) ($response['Status'] ?? '');
        if ($providerStatus === 'FAILED' || $providerId === '') {
            $this->markPayment($paymentId, 'failed', $providerId !== '' ? $providerId : null, $providerStatus !== '' ? $providerStatus : 'FAILED');
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_order') . ' SET payment_status = :status WHERE id_marketplace_order = :id',
                ['status' => 'failed', 'id' => $orderId]
            );
            $this->event($orderId, 'payment_failed', 'Provider declined the payment.');
            $this->audit($actor, 'payment.failed', 'order:' . $orderId, 'provider declined');
            $this->releaseOrderStock($orderId);
            throw new PlatformException('payment_failed', 'The payment was declined. The order was not paid.', 402);
        }
        if ($providerStatus !== 'SUCCEEDED') {
            $this->markPayment($paymentId, 'pending_provider', $providerId, $providerStatus);

            return $this->paymentResult($this->requirePayment($paymentId), 202);
        }

        $payment = $this->markPaid($paymentId, $providerId, $providerStatus, $actor);
        $payment = $this->settleOrDefer($payment);

        return $this->paymentResult($payment, ($payment['settlement_status'] ?? '') === 'pending_transfer' ? 202 : 201);
    }

    /**
     * @return array<string, mixed>
     */
    public function acceptWebhook(string $eventType, string $resourceId, string $rawBody): array
    {
        $eventType = trim($eventType);
        $resourceId = trim($resourceId);
        if (!preg_match('/^[A-Z0-9_]{3,80}$/', $eventType) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $resourceId)) {
            throw new PlatformException('validation_error', 'Webhook event type and resource id are required.', 422);
        }
        $resource = $this->gateway->fetch($eventType, $resourceId);
        if ($resource === null) {
            $this->recordWebhook($eventType, $resourceId, $rawBody, 'rejected');
            throw new PlatformException('webhook_unverified', 'The webhook resource could not be verified with the provider.', 401);
        }
        $inserted = $this->recordWebhook($eventType, $resourceId, $rawBody, 'applied');
        if (!$inserted && $this->webhookStatus($eventType, $resourceId) !== 'deferred') {
            return ['duplicate' => true, 'status' => 'duplicate', 'resource_status' => (string) ($resource['Status'] ?? '')];
        }
        if (str_starts_with($eventType, 'PAYIN')) {
            $this->applyPayInNotification($resource);
        }

        return ['duplicate' => false, 'status' => 'applied', 'resource_status' => (string) ($resource['Status'] ?? '')];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function refund(array $actor, int $orderId, int $amountCents, string $idempotencyKey): array
    {
        $this->assertAdmin($actor);
        $this->idempotencyKey($idempotencyKey);
        if ($amountCents < 1) {
            throw new PlatformException('validation_error', 'Refund amount must be positive.', 422);
        }
        $order = $this->requireOrder($orderId);
        if (!in_array((string) $order['payment_status'], ['paid', 'partially_refunded'], true)) {
            throw new PlatformException('conflict', 'Only a paid order can be refunded.', 409);
        }
        $payment = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_payment') . ' WHERE id_marketplace_order = :id AND status = :status ORDER BY id_marketplace_payment DESC',
            ['id' => $orderId, 'status' => 'succeeded']
        );
        if ($payment === null || (string) $payment['provider_id'] === '') {
            throw new PlatformException('conflict', 'The order has no successful provider payment.', 409);
        }
        $already = (int) $this->store->value(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM ' . $this->t('marketplace_refund') . ' WHERE id_marketplace_order = :id AND status = :status',
            ['id' => $orderId, 'status' => 'succeeded']
        );
        $remaining = (int) $order['grand_cents'] - $already;
        if ($amountCents > $remaining) {
            throw new PlatformException('invalid_amount', 'Refund exceeds the remaining paid amount.', 422);
        }

        $weights = [];
        foreach ($this->vendorOrders($orderId) as $vendorOrder) {
            $refunded = (int) $this->store->value(
                'SELECT COALESCE(SUM(amount_cents), 0) FROM ' . $this->t('marketplace_refund') . ' WHERE id_marketplace_vendor_order = :id AND status = :status',
                ['id' => (int) $vendorOrder['id_marketplace_vendor_order'], 'status' => 'succeeded']
            );
            $left = (int) $vendorOrder['grand_cents'] - $refunded;
            if ($left > 0) {
                $weights[(int) $vendorOrder['id_marketplace_vendor_order']] = $left;
            }
        }
        $shares = $this->shares($amountCents, $weights);
        $provider = $this->gateway->createRefund((string) $payment['provider_id'], $amountCents, $idempotencyKey);
        $providerId = (string) ($provider['Id'] ?? '');
        $now = $this->store->now();
        $rows = [];
        $this->store->transaction(function () use ($shares, $payment, $orderId, $providerId, $now, $actor, &$rows): void {
            foreach ($shares as $vendorOrderId => $share) {
                if ($share < 1) {
                    continue;
                }
                $vendorOrder = $this->requireVendorOrder((int) $vendorOrderId);
                $portion = $this->productPortion($vendorOrder, $share);
                $percent = $this->vendorPercent((int) $vendorOrder['id_marketplace_vendor']);
                $fee = Money::percentOf($portion, $percent);
                $refundId = $this->store->insert('marketplace_refund', [
                    'id_marketplace_payment' => (int) $payment['id_marketplace_payment'],
                    'id_marketplace_order' => $orderId,
                    'id_marketplace_vendor' => (int) $vendorOrder['id_marketplace_vendor'],
                    'id_marketplace_vendor_order' => (int) $vendorOrderId,
                    'amount_cents' => $share,
                    'commission_cents' => $fee,
                    'status' => 'succeeded',
                    'provider_id' => $providerId,
                    'date_add' => $now,
                ]);
                $this->store->insert('marketplace_commission', [
                    'id_marketplace_vendor' => (int) $vendorOrder['id_marketplace_vendor'],
                    'id_marketplace_vendor_order' => (int) $vendorOrderId,
                    'gross_cents' => -$portion,
                    'commission_percent' => $percent,
                    'commission_cents' => -$fee,
                    'net_cents' => -($portion - $fee),
                    'date_add' => $now,
                ]);
                $this->store->insert('marketplace_reconciliation_line', [
                    'id_marketplace_payment' => (int) $payment['id_marketplace_payment'],
                    'id_marketplace_refund' => $refundId,
                    'kind' => 'refund',
                    'amount_cents' => $share,
                    'note' => 'vendor_order:' . $vendorOrderId,
                    'date_add' => $now,
                ]);
                $rows[] = $refundId;
                $this->reverseTransfer($vendorOrder, $share - $fee);
            }
            $refunded = (int) $this->store->value(
                'SELECT COALESCE(SUM(amount_cents), 0) FROM ' . $this->t('marketplace_refund') . ' WHERE id_marketplace_order = :id AND status = :status',
                ['id' => $orderId, 'status' => 'succeeded']
            );
            $status = $refunded >= (int) $this->requireOrder($orderId)['grand_cents'] ? 'refunded' : 'partially_refunded';
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_order') . ' SET payment_status = :status, status = :order_status WHERE id_marketplace_order = :id',
                ['status' => $status, 'order_status' => $status, 'id' => $orderId]
            );
            $this->event($orderId, $status, 'Refund ' . $refunded . ' cents.');
            $this->audit($actor, 'refund.succeeded', 'order:' . $orderId, (string) $refunded);
            foreach (array_keys($shares) as $vendorOrderId) {
                $this->restockIfFullyRefunded((int) $vendorOrderId);
            }
        });

        return [
            'order_id' => $orderId,
            'provider_id' => $providerId,
            'amount_cents' => $amountCents,
            'refund_ids' => $rows,
            'payment_status' => (string) $this->requireOrder($orderId)['payment_status'],
        ];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function requestPayout(array $actor, int $vendorId, int $amountCents, string $idempotencyKey): array
    {
        $this->assertVendor($actor, $vendorId);
        $this->idempotencyKey($idempotencyKey);
        if ($amountCents < 1) {
            throw new PlatformException('validation_error', 'Payout amount must be positive.', 422);
        }
        $identity = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_mangopay_user') . ' WHERE id_marketplace_vendor = :id',
            ['id' => $vendorId]
        );
        if ($identity === null || (string) $identity['kyc_status'] !== 'VALIDATED') {
            throw new PlatformException('kyc_required', 'A validated KYC identity is required before payout.', 409);
        }
        $existing = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_payout') . ' WHERE idempotency_key = :key',
            ['key' => $idempotencyKey]
        );
        if ($existing !== null) {
            if ((int) $existing['id_marketplace_vendor'] !== $vendorId) {
                throw new PlatformException('conflict', 'The idempotency key is already in use.', 409);
            }

            return $this->presentPayout($existing);
        }
        $available = $this->availableBalance($vendorId);
        if ($amountCents > $available) {
            throw new PlatformException('invalid_amount', 'Payout exceeds the available balance.', 422);
        }
        $now = $this->store->now();
        $id = $this->store->insert('marketplace_payout', [
            'id_marketplace_vendor' => $vendorId,
            'amount_cents' => $amountCents,
            'status' => 'requested',
            'provider_id' => null,
            'idempotency_key' => $idempotencyKey,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
        $this->audit($actor, 'payout.requested', 'payout:' . $id, (string) $amountCents);

        return $this->presentPayout($this->requirePayout($id));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function processPayout(array $actor, int $payoutId): array
    {
        $this->assertAdmin($actor);
        $payout = $this->requirePayout($payoutId);
        if ((string) $payout['status'] === 'processed') {
            return $this->presentPayout($payout);
        }
        if ((string) $payout['status'] !== 'requested') {
            throw new PlatformException('conflict', 'Only a requested payout can be processed.', 409);
        }
        $wallet = $this->requireWallet((int) $payout['id_marketplace_vendor']);
        $response = $this->gateway->createPayout([
            'AuthorId' => (string) $wallet['provider_user_id'],
            'DebitedWalletId' => (string) $wallet['provider_wallet_id'],
            'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => (int) $payout['amount_cents']],
            'Fees' => ['Currency' => 'EUR', 'Amount' => 0],
        ]);
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_payout') . ' SET status = :status, provider_id = :provider, date_upd = :now WHERE id_marketplace_payout = :id AND status = :requested',
            [
                'status' => 'processed',
                'provider' => (string) ($response['Id'] ?? ''),
                'now' => $this->store->now(),
                'id' => $payoutId,
                'requested' => 'requested',
            ]
        );
        $this->audit($actor, 'payout.processed', 'payout:' . $payoutId, (string) ($response['Id'] ?? ''));

        return $this->presentPayout($this->requirePayout($payoutId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function commissions(array $actor, int $vendorId): array
    {
        $this->assertVendor($actor, $vendorId);
        $rows = $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_commission') . ' WHERE id_marketplace_vendor = :id ORDER BY id_marketplace_commission',
            ['id' => $vendorId]
        );

        return [
            'available_cents' => $this->availableBalance($vendorId),
            'entries' => array_map(fn (array $row): array => [
                'id' => (int) $row['id_marketplace_commission'],
                'vendor_order_id' => $row['id_marketplace_vendor_order'] === null ? null : (int) $row['id_marketplace_vendor_order'],
                'gross_cents' => (int) $row['gross_cents'],
                'commission_percent' => $this->percent($row['commission_percent']),
                'commission_cents' => (int) $row['commission_cents'],
                'net_cents' => (int) $row['net_cents'],
                'date_add' => (string) $row['date_add'],
            ], $rows),
        ];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return list<array<string, mixed>>
     */
    public function reconciliation(array $actor): array
    {
        $this->assertAdmin($actor);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id_marketplace_reconciliation_line'],
            'payment_id' => $row['id_marketplace_payment'] === null ? null : (int) $row['id_marketplace_payment'],
            'refund_id' => $row['id_marketplace_refund'] === null ? null : (int) $row['id_marketplace_refund'],
            'kind' => (string) $row['kind'],
            'amount_cents' => (int) $row['amount_cents'],
            'note' => (string) $row['note'],
            'date_add' => (string) $row['date_add'],
        ], $this->store->all('SELECT * FROM ' . $this->t('marketplace_reconciliation_line') . ' ORDER BY id_marketplace_reconciliation_line DESC LIMIT 200'));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function ensureIdentity(array $actor, int $vendorId, array $input): array
    {
        $this->assertVendor($actor, $vendorId);
        $existing = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_mangopay_user') . ' WHERE id_marketplace_vendor = :id',
            ['id' => $vendorId]
        );
        if ($existing !== null) {
            return $this->presentIdentity($existing);
        }
        $vendor = $this->requireVendor($vendorId);
        $legal = strtolower((string) ($input['legal_type'] ?? 'natural'));
        if (!in_array($legal, ['natural', 'business'], true)) {
            throw new PlatformException('validation_error', 'Legal type must be natural or business.', 422);
        }
        $first = $this->requiredText($input, 'first_name', 1, 128);
        $last = $this->requiredText($input, 'last_name', 1, 128);
        $country = strtoupper((string) ($input['country'] ?? ''));
        $nationality = strtoupper((string) ($input['nationality'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $country) || !preg_match('/^[A-Z]{2}$/', $nationality)) {
            throw new PlatformException('validation_error', 'Country and nationality must be ISO codes.', 422);
        }
        $birthday = (string) ($input['birthday'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
            throw new PlatformException('validation_error', 'Birthday must be YYYY-MM-DD.', 422);
        }
        $payload = [
            'Email' => (string) $vendor['email'],
            'FirstName' => $first,
            'LastName' => $last,
            'Birthday' => strtotime($birthday . ' 00:00:00 UTC'),
            'Nationality' => $nationality,
            'CountryOfResidence' => $country,
            'TermsAndConditionsAccepted' => true,
            'UserCategory' => 'OWNER',
            'Tag' => 'vendor:' . $vendorId,
        ];
        if ($legal === 'business') {
            $payload['LegalPersonType'] = 'BUSINESS';
            $payload['Name'] = $this->requiredText($input, 'company_name', 2, 255);
            $payload['LegalRepresentativeFirstName'] = $first;
            $payload['LegalRepresentativeLastName'] = $last;
        }
        $created = $this->gateway->createUser($payload);
        $now = $this->store->now();
        $id = $this->store->insert('marketplace_mangopay_user', [
            'id_marketplace_vendor' => $vendorId,
            'provider_id' => (string) $created['Id'],
            'legal_type' => $legal,
            'kyc_status' => 'LIGHT',
            'email' => (string) $vendor['email'],
            'date_add' => $now,
            'date_upd' => $now,
        ]);
        $wallet = $this->gateway->createWallet((string) $created['Id'], 'EUR');
        $this->store->insert('marketplace_mangopay_wallet', [
            'id_marketplace_vendor' => $vendorId,
            'provider_user_id' => (string) $created['Id'],
            'provider_wallet_id' => (string) $wallet['Id'],
            'currency' => 'EUR',
            'date_add' => $now,
        ]);
        $this->audit($actor, 'mangopay.identity', 'vendor:' . $vendorId, (string) $created['Id']);

        return $this->presentIdentity($this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_mangopay_user') . ' WHERE id_marketplace_mangopay_user = :id',
            ['id' => $id]
        ) ?? []);
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function submitVendorKyc(array $actor, int $vendorId): array
    {
        $this->assertVendor($actor, $vendorId);
        $identity = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_mangopay_user') . ' WHERE id_marketplace_vendor = :id',
            ['id' => $vendorId]
        );
        if ($identity === null) {
            throw new PlatformException('kyc_required', 'Create the vendor identity before submitting KYC.', 409);
        }
        $result = $this->gateway->submitKyc((string) $identity['provider_id']);
        $status = (($result['KYCLevel'] ?? '') === 'REGULAR') ? 'VALIDATED' : 'SUBMITTED';
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_mangopay_user') . ' SET kyc_status = :status, date_upd = :now WHERE id_marketplace_vendor = :id',
            ['status' => $status, 'now' => $this->store->now(), 'id' => $vendorId]
        );
        $this->audit($actor, 'mangopay.kyc', 'vendor:' . $vendorId, $status);
        $identity['kyc_status'] = $status;

        return $this->presentIdentity($identity);
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return list<array<string, mixed>>
     */
    public function wallets(array $actor, int $vendorId): array
    {
        $this->assertVendor($actor, $vendorId);

        return array_map(static fn (array $row): array => [
            'currency' => (string) $row['currency'],
            'provider_wallet_id' => (string) $row['provider_wallet_id'],
        ], $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_mangopay_wallet') . ' WHERE id_marketplace_vendor = :id',
            ['id' => $vendorId]
        ));
    }

    public function availableBalance(int $vendorId): int
    {
        $net = (int) $this->store->value(
            'SELECT COALESCE(SUM(net_cents), 0) FROM ' . $this->t('marketplace_commission') . ' WHERE id_marketplace_vendor = :id',
            ['id' => $vendorId]
        );
        $shipping = (int) $this->store->value(
            'SELECT COALESCE(SUM(vo.shipping_cents), 0) FROM ' . $this->t('marketplace_vendor_order') . ' vo INNER JOIN ' . $this->t('marketplace_order') . ' o ON o.id_marketplace_order = vo.id_marketplace_order WHERE vo.id_marketplace_vendor = :id AND o.payment_status IN (\'paid\', \'partially_refunded\', \'refunded\')',
            ['id' => $vendorId]
        );
        $refunded = (int) $this->store->value(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM ' . $this->t('marketplace_refund') . ' WHERE id_marketplace_vendor = :id AND status = :status',
            ['id' => $vendorId, 'status' => 'succeeded']
        );
        $productRefunded = (int) $this->store->value(
            'SELECT COALESCE(SUM(-gross_cents), 0) FROM ' . $this->t('marketplace_commission') . ' WHERE id_marketplace_vendor = :id AND gross_cents < 0',
            ['id' => $vendorId]
        );
        $reserved = (int) $this->store->value(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM ' . $this->t('marketplace_payout') . ' WHERE id_marketplace_vendor = :id AND status IN (\'requested\', \'processed\')',
            ['id' => $vendorId]
        );

        return $net + $shipping - $refunded + $productRefunded - $reserved;
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function settleOrDefer(array $payment): array
    {
        try {
            $this->settleWallets((int) $payment['id_marketplace_payment']);
        } catch (PlatformException $exception) {
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_payment') . ' SET settlement_status = :status, date_upd = :now WHERE id_marketplace_payment = :id',
                ['status' => 'pending_transfer', 'now' => $this->store->now(), 'id' => (int) $payment['id_marketplace_payment']]
            );
            $this->audit(['role' => 'system', 'id' => 0], 'settlement.pending', 'payment:' . $payment['id_marketplace_payment'], $exception->errorCode);
            $payment['settlement_status'] = 'pending_transfer';

            return $payment;
        }
        $payment['settlement_status'] = 'settled';

        return $payment;
    }

    private function settleWallets(int $paymentId): void
    {
        $payment = $this->requirePayment($paymentId);
        if ((string) $payment['status'] !== 'succeeded') {
            return;
        }
        $now = $this->store->now();
        foreach ($this->vendorOrders((int) $payment['id_marketplace_order']) as $vendorOrder) {
            $note = 'vendor_order:' . $vendorOrder['id_marketplace_vendor_order'];
            $existing = $this->store->one(
                'SELECT id_marketplace_reconciliation_line FROM ' . $this->t('marketplace_reconciliation_line') . ' WHERE kind = :kind AND note = :note',
                ['kind' => 'transfer', 'note' => $note]
            );
            if ($existing !== null) {
                continue;
            }
            $amount = $this->transferAmount($vendorOrder);
            if ($amount < 1) {
                continue;
            }
            $wallet = $this->requireWallet((int) $vendorOrder['id_marketplace_vendor']);
            $this->gateway->createTransfer([
                'AuthorId' => (string) $wallet['provider_user_id'],
                'DebitedWalletId' => $this->gateway->platformWalletId(),
                'CreditedWalletId' => (string) $wallet['provider_wallet_id'],
                'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => $amount],
                'Fees' => ['Currency' => 'EUR', 'Amount' => 0],
            ]);
            $this->store->insert('marketplace_reconciliation_line', [
                'id_marketplace_payment' => $paymentId,
                'id_marketplace_refund' => null,
                'kind' => 'transfer',
                'amount_cents' => $amount,
                'note' => $note,
                'date_add' => $now,
            ]);
        }
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_payment') . ' SET settlement_status = :status, date_upd = :now WHERE id_marketplace_payment = :id',
            ['status' => 'settled', 'now' => $now, 'id' => $paymentId]
        );
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function applyPayInNotification(array $resource): void
    {
        $providerId = (string) ($resource['Id'] ?? '');
        if ($providerId === '') {
            return;
        }
        $payment = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_payment') . ' WHERE provider = :provider AND provider_id = :id',
            ['provider' => 'mangopay', 'id' => $providerId]
        );
        if ($payment === null) {
            return;
        }
        $status = (string) ($resource['Status'] ?? '');
        if ($status === 'SUCCEEDED') {
            try {
                $this->ensureReservations((int) $payment['id_marketplace_order']);
            } catch (PlatformException $exception) {
                if ($exception->errorCode !== 'insufficient_stock') {
                    throw $exception;
                }
                $this->store->exec(
                    'UPDATE ' . $this->t('marketplace_payment') . ' SET settlement_status = :status, date_upd = :now WHERE id_marketplace_payment = :id AND status <> :succeeded',
                    ['status' => 'stock_conflict', 'now' => $this->store->now(), 'id' => (int) $payment['id_marketplace_payment'], 'succeeded' => 'succeeded']
                );
                $this->deferWebhook($providerId);
                $this->audit(['role' => 'system', 'id' => 0], 'payment.stock_conflict', 'payment:' . $payment['id_marketplace_payment'], 'reservation missing');

                return;
            }
            $paid = $this->markPaid((int) $payment['id_marketplace_payment'], $providerId, $status, ['role' => 'system', 'id' => 0]);
            $this->settleOrDefer($paid);
            $this->closeWebhook($providerId);

            return;
        }
        if ($status === 'FAILED' && (string) $payment['status'] !== 'succeeded') {
            $this->markPayment((int) $payment['id_marketplace_payment'], 'failed', $providerId, $status);
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_order') . ' SET payment_status = :status WHERE id_marketplace_order = :id AND payment_status <> :paid',
                ['status' => 'failed', 'id' => (int) $payment['id_marketplace_order'], 'paid' => 'paid']
            );
            $this->releaseOrderStock((int) $payment['id_marketplace_order']);
        }
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    private function markPaid(int $paymentId, string $providerId, string $providerStatus, array $actor): array
    {
        $this->store->transaction(function () use ($paymentId, $providerId, $providerStatus, $actor): void {
            $changed = $this->store->exec(
                'UPDATE ' . $this->t('marketplace_payment')
                . ' SET status = :status, provider_id = :provider, provider_status = :provider_status, date_upd = :now'
                . ' WHERE id_marketplace_payment = :id AND status <> \'succeeded\'',
                [
                    'status' => 'succeeded',
                    'provider' => $providerId,
                    'provider_status' => $providerStatus,
                    'now' => $this->store->now(),
                    'id' => $paymentId,
                ]
            );
            if ($changed !== 1) {
                return;
            }
            $payment = $this->requirePayment($paymentId);
            $orderId = (int) $payment['id_marketplace_order'];
            $fee = $this->writeSaleCommissions($orderId);
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_payment') . ' SET commission_cents = :fee WHERE id_marketplace_payment = :id',
                ['fee' => $fee, 'id' => $paymentId]
            );
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_order') . ' SET payment_status = :paid, status = :paid_status, payment_method = :method WHERE id_marketplace_order = :id',
                ['paid' => 'paid', 'paid_status' => 'paid', 'method' => 'mangopay', 'id' => $orderId]
            );
            $this->store->insert('marketplace_reconciliation_line', [
                'id_marketplace_payment' => $paymentId,
                'id_marketplace_refund' => null,
                'kind' => 'payment',
                'amount_cents' => (int) $payment['amount_cents'],
                'note' => 'order:' . $orderId,
                'date_add' => $this->store->now(),
            ]);
            $this->event($orderId, 'paid', 'Payment ' . $providerId);
            $this->audit($actor, 'payment.succeeded', 'payment:' . $paymentId, $providerId);
        });

        return $this->requirePayment($paymentId);
    }

    private function writeSaleCommissions(int $orderId): int
    {
        $total = 0;
        $now = $this->store->now();
        foreach ($this->vendorOrders($orderId) as $vendorOrder) {
            $existing = (int) $this->store->value(
                'SELECT COUNT(*) FROM ' . $this->t('marketplace_commission') . ' WHERE id_marketplace_vendor_order = :id AND gross_cents > 0',
                ['id' => (int) $vendorOrder['id_marketplace_vendor_order']]
            );
            if ($existing > 0) {
                continue;
            }
            $percent = $this->vendorPercent((int) $vendorOrder['id_marketplace_vendor']);
            $gross = (int) $vendorOrder['product_cents'];
            $fee = Money::percentOf($gross, $percent);
            $this->store->insert('marketplace_commission', [
                'id_marketplace_vendor' => (int) $vendorOrder['id_marketplace_vendor'],
                'id_marketplace_vendor_order' => (int) $vendorOrder['id_marketplace_vendor_order'],
                'gross_cents' => $gross,
                'commission_percent' => $percent,
                'commission_cents' => $fee,
                'net_cents' => $gross - $fee,
                'date_add' => $now,
            ]);
            $total += $fee;
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $vendorOrder
     */
    private function transferAmount(array $vendorOrder): int
    {
        $percent = $this->vendorPercent((int) $vendorOrder['id_marketplace_vendor']);
        $fee = Money::percentOf((int) $vendorOrder['product_cents'], $percent);

        return (int) $vendorOrder['product_cents'] - $fee + (int) $vendorOrder['shipping_cents'];
    }

    /**
     * @param array<string, mixed> $vendorOrder
     */
    private function productPortion(array $vendorOrder, int $share): int
    {
        $refundedProduct = (int) $this->store->value(
            'SELECT COALESCE(SUM(-gross_cents), 0) FROM ' . $this->t('marketplace_commission')
            . ' WHERE id_marketplace_vendor_order = :id AND gross_cents < 0',
            ['id' => (int) $vendorOrder['id_marketplace_vendor_order']]
        );
        $remainingProduct = max(0, (int) $vendorOrder['product_cents'] - $refundedProduct);

        return min($share, $remainingProduct);
    }

    /**
     * @param array<string, mixed> $vendorOrder
     */
    private function reverseTransfer(array $vendorOrder, int $amount): void
    {
        if ($amount < 1) {
            return;
        }
        $wallet = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_mangopay_wallet') . ' WHERE id_marketplace_vendor = :id AND currency = :currency',
            ['id' => (int) $vendorOrder['id_marketplace_vendor'], 'currency' => 'EUR']
        );
        if ($wallet === null) {
            return;
        }
        try {
            $this->gateway->createTransfer([
                'AuthorId' => (string) $wallet['provider_user_id'],
                'DebitedWalletId' => (string) $wallet['provider_wallet_id'],
                'CreditedWalletId' => $this->gateway->platformWalletId(),
                'DebitedFunds' => ['Currency' => 'EUR', 'Amount' => $amount],
                'Fees' => ['Currency' => 'EUR', 'Amount' => 0],
            ]);
        } catch (PlatformException $exception) {
            $this->store->insert('marketplace_reconciliation_line', [
                'id_marketplace_payment' => null,
                'id_marketplace_refund' => null,
                'kind' => 'transfer_reversal_failed',
                'amount_cents' => $amount,
                'note' => 'vendor_order:' . $vendorOrder['id_marketplace_vendor_order'],
                'date_add' => $this->store->now(),
            ]);
            $this->audit(['role' => 'system', 'id' => 0], 'transfer.reverse_failed', 'vendor_order:' . $vendorOrder['id_marketplace_vendor_order'], $exception->errorCode);
        }
    }

    /**
     * @param array<string, mixed> $shopper
     */
    private function ensureShopperPayer(array $shopper): string
    {
        if ((string) ($shopper['mangopay_user_id'] ?? '') !== '') {
            return (string) $shopper['mangopay_user_id'];
        }
        $created = $this->gateway->createUser([
            'Email' => (string) $shopper['email'],
            'FirstName' => (string) $shopper['first_name'],
            'LastName' => (string) $shopper['last_name'],
            'UserCategory' => 'PAYER',
            'TermsAndConditionsAccepted' => true,
            'Tag' => 'shopper:' . $shopper['id_marketplace_shopper'],
        ]);
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_shopper') . ' SET mangopay_user_id = :provider WHERE id_marketplace_shopper = :id',
            ['provider' => (string) $created['Id'], 'id' => (int) $shopper['id_marketplace_shopper']]
        );

        return (string) $created['Id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireWallet(int $vendorId): array
    {
        $wallet = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_mangopay_wallet') . ' WHERE id_marketplace_vendor = :id AND currency = :currency',
            ['id' => $vendorId, 'currency' => 'EUR']
        );
        if ($wallet === null) {
            throw new PlatformException('vendor_onboarding_required', 'The vendor has not completed Mangopay onboarding.', 409);
        }

        return $wallet;
    }

    private function vendorPercent(int $vendorId): string
    {
        $vendor = $this->requireVendor($vendorId);

        return $this->percent($vendor['commission_percent']);
    }

    private function markPayment(int $paymentId, string $status, ?string $providerId, string $providerStatus): void
    {
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_payment') . ' SET status = :status, provider_id = :provider, provider_status = :provider_status, date_upd = :now WHERE id_marketplace_payment = :id',
            [
                'status' => $status,
                'provider' => $providerId,
                'provider_status' => $providerStatus,
                'now' => $this->store->now(),
                'id' => $paymentId,
            ]
        );
    }

    private function webhookStatus(string $eventType, string $resourceId): string
    {
        return $this->store->value(
            'SELECT status FROM ' . $this->t('marketplace_webhook_event') . ' WHERE event_type = :event AND resource_id = :id',
            ['event' => $eventType, 'id' => $resourceId]
        );
    }

    private function deferWebhook(string $resourceId): void
    {
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_webhook_event') . ' SET status = :deferred WHERE resource_id = :id AND status = :applied',
            ['deferred' => 'deferred', 'id' => $resourceId, 'applied' => 'applied']
        );
    }

    private function closeWebhook(string $resourceId): void
    {
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_webhook_event') . ' SET status = :applied WHERE resource_id = :id AND status = :deferred',
            ['applied' => 'applied', 'id' => $resourceId, 'deferred' => 'deferred']
        );
    }

    private function recordWebhook(string $eventType, string $resourceId, string $rawBody, string $status): bool
    {
        try {
            $this->store->insert('marketplace_webhook_event', [
                'event_type' => $eventType,
                'resource_id' => $resourceId,
                'payload_hash' => hash('sha256', $rawBody),
                'status' => $status,
                'date_add' => $this->store->now(),
            ]);

            return true;
        } catch (\PDOException $exception) {
            if ($this->store->isUniqueViolation($exception)) {
                return false;
            }
            throw $exception;
        }
    }

    /**
     * @param array<int, int> $weights
     * @return array<int, int>
     */
    private function shares(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($total < 1 || $amount < 1 || $amount > $total) {
            throw new PlatformException('invalid_amount', 'Refund exceeds the remaining amount.', 422);
        }
        $keys = array_keys($weights);
        $left = $amount;
        $shares = [];
        foreach ($keys as $index => $key) {
            if ($index === count($keys) - 1) {
                $shares[$key] = $left;
                break;
            }
            $share = intdiv($amount * $weights[$key], $total);
            $shares[$key] = $share;
            $left -= $share;
        }

        return $shares;
    }

    private function cardAlias(string $cardId): string
    {
        if (preg_match('/^[0-9]{13,19}$/', $cardId)) {
            return 'redacted';
        }

        return substr($cardId, 0, 64);
    }

    private function idempotencyKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,80}$/', $key)) {
            throw new PlatformException('validation_error', 'Idempotency-Key must be 8 to 80 characters.', 422);
        }
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function paymentResult(array $payment, int $http): array
    {
        $presented = $this->presentPayment($payment);
        $presented['http'] = $http;

        return $presented;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentPayment(array $row): array
    {
        return [
            'id' => (int) $row['id_marketplace_payment'],
            'order_id' => (int) $row['id_marketplace_order'],
            'status' => (string) $row['status'],
            'settlement_status' => (string) $row['settlement_status'],
            'amount_cents' => (int) $row['amount_cents'],
            'commission_cents' => (int) $row['commission_cents'],
            'currency' => (string) $row['currency'],
            'card_alias' => (string) ($row['card_alias'] ?? ''),
            'provider_status' => (string) ($row['provider_status'] ?? ''),
            'provider_id' => (string) ($row['provider_id'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentPayout(array $row): array
    {
        return [
            'id' => (int) $row['id_marketplace_payout'],
            'vendor_id' => (int) $row['id_marketplace_vendor'],
            'amount_cents' => (int) $row['amount_cents'],
            'status' => (string) $row['status'],
            'provider_id' => (string) ($row['provider_id'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentIdentity(array $row): array
    {
        return [
            'vendor_id' => (int) ($row['id_marketplace_vendor'] ?? 0),
            'provider_id' => (string) ($row['provider_id'] ?? ''),
            'legal_type' => (string) ($row['legal_type'] ?? ''),
            'kyc_status' => (string) ($row['kyc_status'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePayment(int $id): array
    {
        $row = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_payment') . ' WHERE id_marketplace_payment = :id',
            ['id' => $id]
        );
        if ($row === null) {
            throw new PlatformException('not_found', 'The payment was not found.', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePayout(int $id): array
    {
        $row = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_payout') . ' WHERE id_marketplace_payout = :id',
            ['id' => $id]
        );
        if ($row === null) {
            throw new PlatformException('not_found', 'The payout was not found.', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentForActor(array $actor, int $paymentId): array
    {
        $payment = $this->requirePayment($paymentId);
        if ($actor['role'] === 'admin') {
            return $this->presentPayment($payment);
        }
        if ($actor['role'] === 'shopper' && (int) $payment['id_marketplace_shopper'] === (int) $actor['id']) {
            return $this->presentPayment($payment);
        }
        throw new PlatformException('not_found', 'The payment was not found.', 404);
    }
}
