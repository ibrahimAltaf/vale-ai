# Mangopay integration

The client is `Marketplace\Platform\Mangopay\HttpGateway`. It uses OAuth `client_credentials` against the sandbox host unless `MARKETPLACE_MANGOPAY_BASE_URL` is set. Pay-ins go to `POST /v2.01/{clientId}/payins/card/direct`. The body contains `CardId`, debited funds, fees, and `SecureModeReturnURL`. `IdempotencyKey` is removed from the JSON and sent as the `Idempotency-Key` header, truncated to 36 characters. The API key is not copied into logs.

`CardPayload` rejects raw card fields before a gateway call. A numeric 13–19 digit card id is stored as the alias `redacted`, because Mangopay card ids can be numeric. The alias is the only card attribute persisted.

## Gateways

| Class | When it is used | What a success means |
| --- | --- | --- |
| `HttpGateway` | Client id and API key are both set | A real HTTP call. Still not production verification until the sandbox call is recorded |
| `MemoryGateway` | PHPUnit | In-process fake. `card_fail` declines. `card_pending` stays created |
| `UnavailableGateway` | Credentials are missing | Every payment method throws `mangopay_not_configured` or the equivalent fail-closed error |

`GET /api/v1/health` sets `live_provider_verified` to false in code. Do not change that without a real sandbox call and a note in the acceptance report.

## Onboarding and settlement

`POST /api/v1/vendors/me/mangopay/identity` creates one legal user and one EUR wallet. The memory gateway maps a regular user to KYC `VALIDATED` so payout tests can run. The HTTP `submitKyc` path records `SUBMITTED` and does not claim the provider has validated the document.

On a succeeded pay-in the platform:

1. Marks the payment succeeded only if it was not already succeeded.
2. Writes one commission row per vendor sub-order.
3. Transfers the vendor amount: product cents minus commission plus shipping cents.
4. Fees on that transfer are zero because the commission already stayed with the platform wallet side of the pay-in.

If the transfer throws, the pay-in stays succeeded and `settlement_status` is `pending_transfer`.

Payouts require `kyc_status = VALIDATED` and a balance that covers the amount. An administrator calls `POST /api/v1/admin/payouts/{id}/process`. The HTTP gateway requires `MARKETPLACE_MANGOPAY_BANK_ACCOUNT`. Processing an already processed payout returns the same row.

## Sandbox status

**BLOCKED.** This workspace has no `MARKETPLACE_MANGOPAY_CLIENT_ID` or `MARKETPLACE_MANGOPAY_API_KEY`. `HttpGatewayTest` checks the request shape against a fake transport. It does not call `api.sandbox.mangopay.com`. No live pay-in, refund, or payout has been verified.

## Official SDK

The repository does not depend on the Mangopay PHP SDK. Adding it would be a new Composer dependency. The HTTP client covers the pay-in, user, wallet, transfer, refund, and payout calls this backend uses. If the SDK is adopted later, keep `Gateway` as the boundary and keep card data out of the database.
