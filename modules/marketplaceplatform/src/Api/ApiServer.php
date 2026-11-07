<?php

declare(strict_types=1);

namespace Marketplace\Platform\Api;

use Marketplace\Platform\Mangopay\CardPayload;
use Marketplace\Platform\Platform;
use Marketplace\Platform\PlatformException;

/**
 * Versioned JSON API. Financial POSTs require an idempotency key.
 */
final class ApiServer
{
    private bool $permissionsReady = false;

    /** @var list<string> */
    private array $financial = ['checkout', 'pay', 'refund', 'payout'];

    public function __construct(private readonly Platform $platform)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $method, string $path, array $query, array $body, array $headers, string $clientKey): array
    {
        $this->seedPermissions();
        $headers = array_change_key_case($headers);
        $route = RouteMap::match($method, $path);
        if ($route === null) {
            return $this->error(404, 'not_found', 'That API route does not exist.');
        }
        foreach ($route['params'] as $value) {
            if (!preg_match('/^[1-9][0-9]{0,9}$/', $value)) {
                return $this->error(422, 'validation_error', 'A path parameter must be a positive integer.');
            }
        }

        try {
            CardPayload::reject($body);
            if ($route['roles'] === []) {
                Payload::check($route['name'], $body);
            }
        } catch (PlatformException $exception) {
            return $this->error($exception->status, $exception->errorCode, $exception->getMessage());
        }

        $actor = null;
        $limitKey = hash('sha256', $clientKey);
        if ($route['roles'] !== []) {
            $header = (string) ($headers['authorization'] ?? '');
            if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $matches)) {
                return $this->error(401, 'unauthorized', 'Authentication is required.');
            }
            try {
                $actor = $this->platform->authenticate($matches[1]);
            } catch (PlatformException $exception) {
                return $this->error($exception->status, $exception->errorCode, $exception->getMessage());
            }
            if (!in_array($actor['role'], $route['roles'], true)) {
                return $this->error(403, 'forbidden', 'This account cannot call that route.');
            }
            if (!$this->platform->allows($actor['role'], $route['permission'])) {
                return $this->error(403, 'forbidden', 'This account does not have that permission.');
            }
            try {
                Payload::check($route['name'], $body);
            } catch (PlatformException $exception) {
                return $this->error($exception->status, $exception->errorCode, $exception->getMessage());
            }
            $limitKey = $actor['token_hash'];
        }

        if ($route['name'] !== 'health') {
            if ($this->platform->tooMany($limitKey, time())) {
                return $this->error(429, 'rate_limited', 'Too many requests. Try again in a minute.');
            }
            $this->platform->hit($limitKey, time());
        }

        $idempotencyKey = trim((string) ($headers['idempotency-key'] ?? ''));
        $requestHash = hash('sha256', (string) json_encode($body));
        $actorKey = $actor === null ? 'public' : $actor['role'] . ':' . $actor['id'];
        if (in_array($route['name'], $this->financial, true)) {
            if (!preg_match('/^[A-Za-z0-9_.:-]{8,80}$/', $idempotencyKey)) {
                return $this->error(422, 'validation_error', 'Idempotency-Key must be 8 to 80 characters.');
            }
            $prior = $this->platform->recallIdempotency($actorKey, $idempotencyKey);
            if ($prior !== null) {
                if (!hash_equals((string) $prior['request_hash'], $requestHash)) {
                    return $this->error(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
                }
                $stored = json_decode((string) $prior['response_json'], true);

                return [
                    'status' => (int) $prior['status_code'],
                    'body' => is_array($stored) ? $stored : ['error' => ['code' => 'internal_error', 'message' => 'The stored response could not be read.']],
                ];
            }
        }

        try {
            $result = $this->dispatch($route, $actor ?? ['role' => 'public', 'id' => 0], $query, $body, $idempotencyKey);
        } catch (PlatformException $exception) {
            $result = $this->error($exception->status, $exception->errorCode, $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log('marketplace api: ' . $exception->getMessage());
            $result = $this->error(500, 'internal_error', 'The request could not be completed.');
        }

        if (in_array($route['name'], $this->financial, true) && $result['status'] < 500) {
            try {
                $this->platform->rememberIdempotency($actorKey, $idempotencyKey, $requestHash, $result['status'], (string) json_encode($result['body']));
            } catch (PlatformException) {
                $prior = $this->platform->recallIdempotency($actorKey, $idempotencyKey);
                if ($prior !== null) {
                    $stored = json_decode((string) $prior['response_json'], true);
                    if (is_array($stored)) {
                        return ['status' => (int) $prior['status_code'], 'body' => $stored];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array{method: string, path: string, name: string, roles: list<string>, permission: string, tag: string, summary: string, params: array<string, string>} $route
     * @param array{role: string, id: int} $actor
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function dispatch(array $route, array $actor, array $query, array $body, string $idempotencyKey): array
    {
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 20);
        $params = $route['params'];
        $name = $route['name'];

        if ($name === 'health') {
            return $this->data(200, $this->platform->health());
        }
        if ($name === 'registerVendor') {
            return $this->data(201, $this->platform->registerVendor($body));
        }
        if ($name === 'registerCustomer') {
            return $this->data(201, $this->platform->registerShopper($body));
        }
        if ($name === 'login') {
            return $this->data(200, $this->platform->login((string) ($body['role'] ?? ''), (string) ($body['email'] ?? ''), (string) ($body['password'] ?? '')));
        }
        if ($name === 'logout') {
            $this->platform->revokeToken((string) ($actor['token_hash'] ?? ''));

            return $this->data(200, ['revoked' => true]);
        }
        if ($name === 'passwordReset') {
            return $this->data(200, $this->platform->requestPasswordReset((string) ($body['role'] ?? ''), (string) ($body['email'] ?? '')));
        }
        if ($name === 'passwordConfirm') {
            return $this->data(200, $this->platform->confirmPasswordReset((string) ($body['role'] ?? ''), (string) ($body['email'] ?? ''), (string) ($body['token'] ?? ''), (string) ($body['password'] ?? '')));
        }
        if ($name === 'customerMe') {
            return $this->data(200, $this->platform->shopperProfile($actor));
        }
        if ($name === 'linkChallenge') {
            return $this->data(201, $this->platform->requestCustomerLink($actor, (int) ($body['id_customer'] ?? 0)));
        }
        if ($name === 'confirmLink') {
            return $this->data(200, $this->platform->confirmCustomerLink($actor, (int) ($body['shopper_id'] ?? 0), (int) ($body['id_customer'] ?? 0), (string) ($body['code'] ?? '')));
        }
        if ($name === 'vendorSearch') {
            return $this->page($this->platform->searchVendors((string) ($query['q'] ?? ''), $page, $perPage));
        }
        if ($name === 'publicVendor') {
            return $this->data(200, $this->platform->publicVendor((int) $params['id']));
        }
        if ($name === 'uploadLogo') {
            return $this->data(200, $this->platform->uploadLogo($actor, (string) ($body['content_base64'] ?? '')));
        }
        if ($name === 'statement') {
            return $this->data(200, $this->platform->statement($actor, (int) $actor['id'], (string) ($query['month'] ?? '')));
        }
        if ($name === 'exportStatement') {
            return $this->data(200, ['filename' => 'statement.csv', 'content' => $this->platform->exportStatement($actor, (int) $actor['id'], (string) ($query['month'] ?? ''))]);
        }
        if ($name === 'creditNote') {
            return $this->data(201, $this->platform->createCreditNote($actor, (int) ($body['vendor_order_id'] ?? 0)));
        }
        if ($name === 'setVat') {
            return $this->data(200, $this->platform->setVatRate($actor, (int) $params['id'], (string) ($body['vat_rate_percent'] ?? '')));
        }
        if ($name === 'vendorMe') {
            return $this->data(200, $this->platform->vendorProfile($actor, (int) $actor['id']));
        }
        if ($name === 'updateVendor') {
            return $this->data(200, $this->platform->updateVendor($actor, (int) $actor['id'], $body));
        }
        if ($name === 'shippingRule') {
            return $this->data(201, $this->platform->saveShippingRule($actor, (int) $actor['id'], $body));
        }
        if ($name === 'products') {
            return $this->page($this->platform->listProducts($page, $perPage));
        }
        if ($name === 'offers') {
            $filters = [];
            if (isset($query['vendor_id'])) {
                $filters['vendor_id'] = (int) $query['vendor_id'];
            }
            if (isset($query['vendor_ids'])) {
                $filters['vendor_ids'] = array_map('intval', explode(',', (string) $query['vendor_ids']));
            }
            if (isset($query['product_id'])) {
                $filters['product_id'] = (int) $query['product_id'];
            }
            if (isset($query['ean'])) {
                $filters['ean'] = (string) $query['ean'];
            }

            return $this->page($this->platform->listOffers($filters, $page, $perPage, (string) ($query['sort'] ?? 'id'), (string) ($query['direction'] ?? 'asc')));
        }
        if ($name === 'upsertOffer') {
            return $this->data(201, $this->platform->upsertOffer($actor, (int) $actor['id'], $body));
        }
        if ($name === 'inventory') {
            return $this->page($this->platform->listOffers(['vendor_id' => (int) $actor['id']], $page, $perPage, 'sku', 'asc'));
        }
        if ($name === 'setStock') {
            return $this->data(200, $this->platform->setStock($actor, (int) $actor['id'], (int) $params['offerId'], $body['quantity'] ?? -1));
        }
        if ($name === 'quote') {
            return $this->data(200, $this->platform->quote(
                (int) ($body['vendor_id'] ?? 0),
                (string) ($body['postcode'] ?? ''),
                (int) ($body['product_cents'] ?? 0),
                (int) ($body['weight_grams'] ?? 0),
                (int) ($body['volume_cm3'] ?? 0),
                (bool) ($body['pallet_only'] ?? false),
            ));
        }
        if ($name === 'optimize' || $name === 'validate') {
            $lines = $body['lines'] ?? [];

            return $this->data(200, $this->platform->optimizeCart((string) ($body['postcode'] ?? ''), is_array($lines) ? $lines : []));
        }
        if ($name === 'checkout') {
            $lines = $body['lines'] ?? [];

            return $this->data(201, $this->platform->checkout($actor, (string) ($body['postcode'] ?? ''), is_array($lines) ? $lines : []));
        }
        if ($name === 'orders') {
            return $this->page($this->platform->listOrders($actor, $page, $perPage));
        }
        if ($name === 'order') {
            return $this->data(200, $this->platform->orderFor($actor, (int) $params['id']));
        }
        if ($name === 'cancelOrder') {
            return $this->data(200, $this->platform->cancelOrder($actor, (int) $params['id']));
        }
        if ($name === 'vendorOrders') {
            return $this->page($this->platform->listVendorOrders($actor, (int) $actor['id'], $page, $perPage));
        }
        if ($name === 'fulfill') {
            return $this->data(200, $this->platform->fulfill($actor, (int) $params['id']));
        }
        if ($name === 'pay') {
            $payment = $this->platform->pay(
                $actor,
                (int) ($body['order_id'] ?? 0),
                (string) ($body['card_id'] ?? ''),
                (string) ($body['return_url'] ?? ''),
                $idempotencyKey,
            );
            $status = (int) $payment['http'];
            unset($payment['http']);

            return $this->data($status, $payment);
        }
        if ($name === 'payment') {
            return $this->data(200, $this->platform->paymentForActor($actor, (int) $params['id']));
        }
        if ($name === 'webhook') {
            return $this->data(200, $this->platform->acceptWebhook((string) ($body['EventType'] ?? ''), (string) ($body['RessourceId'] ?? ''), (string) json_encode($body)));
        }
        if ($name === 'identity') {
            return $this->data(201, $this->platform->ensureIdentity($actor, (int) $actor['id'], $body));
        }
        if ($name === 'kyc') {
            return $this->data(200, $this->platform->submitVendorKyc($actor, (int) $actor['id']));
        }
        if ($name === 'wallets') {
            return $this->data(200, ['wallets' => $this->platform->wallets($actor, (int) $actor['id'])]);
        }
        if ($name === 'refund') {
            return $this->data(201, $this->platform->refund($actor, (int) ($body['order_id'] ?? 0), (int) ($body['amount_cents'] ?? 0), $idempotencyKey));
        }
        if ($name === 'payout') {
            return $this->data(201, $this->platform->requestPayout($actor, (int) $actor['id'], (int) ($body['amount_cents'] ?? 0), $idempotencyKey));
        }
        if ($name === 'payouts') {
            return $this->data(200, ['payouts' => $this->platform->listPayouts($actor, (int) $actor['id'])]);
        }
        if ($name === 'processPayout') {
            return $this->data(200, $this->platform->processPayout($actor, (int) $params['id']));
        }
        if ($name === 'commissions') {
            return $this->data(200, $this->platform->commissions($actor, (int) $actor['id']));
        }
        if ($name === 'reconciliation') {
            return $this->data(200, ['entries' => $this->platform->reconciliation($actor)]);
        }
        if ($name === 'audit') {
            return $this->data(200, ['entries' => $this->platform->auditLog($actor)]);
        }
        if ($name === 'import') {
            return $this->data(201, $this->platform->importCsv($actor, (int) $actor['id'], (string) ($body['filename'] ?? 'import.csv'), (string) ($body['csv'] ?? '')));
        }
        if ($name === 'imports') {
            return $this->data(200, ['imports' => $this->platform->listImports($actor, (int) $actor['id'])]);
        }
        if ($name === 'scheduleImport') {
            return $this->data(201, $this->platform->scheduleImport($actor, (int) $actor['id'], (string) ($body['filename'] ?? 'import.csv'), (string) ($body['csv'] ?? ''), (int) ($body['interval_minutes'] ?? 0)));
        }
        if ($name === 'exportOffers') {
            return $this->data(200, ['filename' => 'offers.csv', 'content' => $this->platform->exportOffers($actor, (int) $actor['id'])]);
        }
        if ($name === 'exportOrders') {
            return $this->data(200, ['filename' => 'orders.csv', 'content' => $this->platform->exportOrders($actor, (int) $actor['id'])]);
        }
        if ($name === 'createInvoice') {
            return $this->data(201, $this->platform->createCustomerInvoice($actor, (int) ($body['vendor_order_id'] ?? 0)));
        }
        if ($name === 'issueInvoice') {
            return $this->data(200, $this->platform->issueInvoice($actor, (int) $params['id']));
        }
        if ($name === 'uploadInvoice') {
            return $this->data(200, $this->platform->uploadInvoice($actor, (int) $params['id'], (string) ($body['content_base64'] ?? '')));
        }
        if ($name === 'invoices') {
            return $this->page($this->platform->listInvoices($actor, $page, $perPage));
        }
        if ($name === 'invoice') {
            return $this->data(200, $this->platform->invoiceFor($actor, (int) $params['id']));
        }
        if ($name === 'downloadInvoice') {
            return $this->data(200, $this->platform->downloadInvoice($actor, (int) $params['id']));
        }
        if ($name === 'commissionInvoice') {
            return $this->data(201, $this->platform->createCommissionInvoice($actor, (int) ($body['vendor_order_id'] ?? 0)));
        }
        if ($name === 'adminVendors') {
            return $this->page($this->platform->listVendors($actor, $page, $perPage));
        }
        if ($name === 'approveVendor') {
            return $this->data(200, $this->platform->approveVendor($actor, (int) $params['id']));
        }
        if ($name === 'suspendVendor') {
            return $this->data(200, $this->platform->suspendVendor($actor, (int) $params['id']));
        }
        if ($name === 'setCommission') {
            return $this->data(200, $this->platform->setCommission($actor, (int) $params['id'], (string) ($body['commission_percent'] ?? '')));
        }
        if ($name === 'adminOrders') {
            return $this->page($this->platform->listOrders($actor, $page, $perPage));
        }
        if ($name === 'permissions') {
            return $this->data(200, ['permissions' => $this->platform->permissionList($actor)]);
        }
        if ($name === 'maintenance') {
            return $this->data(200, $this->platform->runMaintenance(time()));
        }

        return $this->error(404, 'not_found', 'That API route does not exist.');
    }

    private function seedPermissions(): void
    {
        if ($this->permissionsReady) {
            return;
        }
        foreach (RouteMap::routes() as $route) {
            foreach ($route['roles'] as $role) {
                $this->platform->grant($role, $route['permission']);
            }
        }
        $this->permissionsReady = true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: array<string, mixed>}
     */
    private function data(int $status, array $data): array
    {
        return ['status' => $status, 'body' => ['data' => $data]];
    }

    /**
     * @param array{data: list<array<string, mixed>>, total: int, page: int, per_page: int} $page
     * @return array{status: int, body: array<string, mixed>}
     */
    private function page(array $page): array
    {
        return [
            'status' => 200,
            'body' => [
                'data' => $page['data'],
                'meta' => [
                    'page' => $page['page'],
                    'per_page' => $page['per_page'],
                    'total' => $page['total'],
                ],
            ],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function error(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => $message]]];
    }
}
