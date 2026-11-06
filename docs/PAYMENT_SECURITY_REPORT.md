# Payment security report

Date: 7 November 2026. Amounts are integer cents. `Money::percentOf` uses half-up basis points: `intdiv(amount * bps + 5000, 10000)`. Shipping is not commissioned. No floating-point money type was introduced.

## Provider status

**BLOCKED.** The workspace has no Mangopay sandbox client id, API key, platform wallet, or payout bank account. Health on the shop returns `payment_mode: unconfigured`, `provider_configured: false`, `live_provider_verified: false`. That last flag is hard-coded false. This audit did not set it.

When both client id and API key exist, `HttpGateway` calls `api.sandbox.mangopay.com`. Otherwise `UnavailableGateway` returns `503 mangopay_not_configured`. Tests use `MemoryGateway`.

Credentials required before any live check:

| Variable | Used for |
| --- | --- |
| `MARKETPLACE_MANGOPAY_CLIENT_ID` | Sandbox API user |
| `MARKETPLACE_MANGOPAY_API_KEY` | Sandbox API password. Must stay in the environment, not in Git, logs, or OpenAPI |
| `MARKETPLACE_MANGOPAY_PLATFORM_WALLET` | Commission and transfer destination |
| `MARKETPLACE_MANGOPAY_BANK_ACCOUNT` | Payout destination |

Tests still required after those values exist, against the sandbox, not the memory gateway:

1. Pay-in `SUCCEEDED` for a reserved order, one commission row, one transfer.
2. The same idempotency key and body returns the stored response and does not create a second pay-in.
3. A second webhook delivery with the same `EventType` and `RessourceId` does not insert a second commission.
4. A failed pay-in leaves the order unpaid, writes no commission, and releases stock.
5. A refund of 2500 cents on a 10000 cent product at 10 percent reverses 250 cents and leaves available balance 6750 when shipping is 0.
6. A payout with KYC `VALIDATED` debits available balance once.
7. A transfer failure after a successful pay-in stays `pending_transfer` and does not mark the order unpaid.

`HttpGatewayTest` already checks the pay-in JSON uses `CardId` and does not send a CVV field or the API key as a header. That is a shape test, not item 1.

## State rules that were tested locally

| Rule | Evidence |
| --- | --- |
| Failed card does not pay and does not write commission | Existing flow test, still passing |
| Failed card releases the last unit | `testFailedPaymentReleasesTheLastUnit`. Second checkout 201 |
| Paid reservation survives `releaseExpired` | `testPaidReservationSurvivesExpiryAndBlocksASecondSale` |
| Pay after expiry does not charge | `testPaymentAfterTheHoldExpiresDoesNotChargeWithoutStock`. 409, order unpaid |
| Webhook success without stock | Code sets `stock_conflict`, skips `markPaid`, defers the webhook. A dedicated PHPUnit case for that branch was not added. Treat the webhook branch as implemented and **not covered by its own assertion** |
| Partial refund 2500 of 10000 at 10% | Flow test, available 6750. Partial refund does not restock |
| Full refund of an unfulfilled vendor order | `restockIfFullyRefunded` releases when succeeded refunds cover the vendor grand total. No new assertion inserts that case alone |
| Idempotency conflict | Flow test, different body returns 409 |
| Duplicate webhook | Flow test, second delivery does not double commission |
| Raw PAN or CVV field | `card_data_rejected`. A numeric card id is stored as alias `redacted` |
| Concurrent payment | **NOT TESTED** as two pay calls. Concurrent checkout was tested |

## Reconciliation

`GET /api/v1/admin/reconciliation` returns at most 200 newest lines and is admin-only. A failed vendor transfer writes a reconciliation line and leaves the order paid. That is deliberate: the pay-in succeeded. An operator retries settlement. The memory gateway can still return `502 transfer_failed` in tests. A live retry was not run.

KYC: the memory gateway maps `REGULAR` to `VALIDATED`. The HTTP gateway's `submitKyc` stays `SUBMITTED` and must not be described as validated. Payout without `VALIDATED` returns `409 kyc_required`.

## Logging

Payment, refund, and payout transitions write `marketplace_audit_log`. The shop front controller logs `marketplace api {requestId}: {message}` for unexpected exceptions and does not put that message in the JSON body. Tokens and the Mangopay key are not written by that line. Audit read is `GET /api/v1/admin/audit`.
