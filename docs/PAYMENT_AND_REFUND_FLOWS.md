# Payment and refund flows

## Pay

1. The shopper calls `POST /api/v1/checkout`. Stock is reserved for 30 minutes and the order is `reserved` / `unpaid`.
2. The shopper calls `POST /api/v1/payments` with `order_id`, `card_id`, an HTTPS `return_url`, and `Idempotency-Key`.
3. Every vendor on the order must already have a wallet, or the call returns `409 vendor_onboarding_required`.
4. The hold is checked again before the provider is called. Missing stock returns `409 insufficient_stock` and does not charge.
5. `card_fail` (memory gateway) or a provider `FAILED` status sets the order to `payment_status=failed`, releases the reservation, returns `402 payment_failed`, and writes no commission.
6. A pending provider status returns `202` and `pending_provider`.
7. `SUCCEEDED` marks the payment, writes commissions, and transfers vendor funds. A successful webhook that finds no stock does not mark the order paid. Settlement status becomes `stock_conflict`.

A failed pay-in and a failed transfer are different. Only a failed pay-in leaves the order unpaid.

## Commission

For a vendor product subtotal of 10000 cents at 10.00 percent, `Money::percentOf` returns 1000 cents commission and 9000 cents net. Shipping is added to the vendor transfer and to the available balance, and it is not included in the commission gross.

The ledger row is:

| Column | Sale of 10000 at 10% |
| --- | --- |
| `gross_cents` | 10000 |
| `commission_cents` | 1000 |
| `net_cents` | 9000 |

## Refund

`POST /api/v1/admin/refunds` is administrator-only. The amount must be positive and no greater than `grand_cents` minus succeeded refunds. The amount is split across vendor sub-orders by the remaining grand total of each shipment. The product portion of a share is capped at the product cents not already reversed. Commission on that portion uses `Money::percentOf`, then the ledger stores the negative of those figures.

A partial refund of 2500 cents against a 10000 cent product at 10 percent reverses 250 cents of commission. The vendor available balance becomes 6750 cents when shipping is zero: net 9000, minus refund 2500, plus the product portion already removed from net (2500 - 250 = 2250 added back). The formula is:

```text
available = sum(net_cents) + shipping on paid orders - refund amounts + product portion of refunds - requested or processed payouts
```

The product portion is added back because `net_cents` already subtracted it. The order becomes `partially_refunded` or `refunded`. A full refund of an unfulfilled vendor order releases its stock reservation. A partial refund does not restock. A fulfilled order is not restocked by the refund. The provider refund is created before the ledger transaction. A later transfer reversal moves `share - fee` from the vendor wallet back toward the platform wallet. If that reversal fails, the refund row remains and a `transfer_reversal_failed` reconciliation line is written.

## Payout

Available balance uses the formula above. The vendor must have KYC `VALIDATED`. `processPayout` is idempotent once the status is `processed`.

## Audit

Payment success, refund success, payout request, payout processing, and settlement deferral write `marketplace_audit_log` and, where money moved, `marketplace_reconciliation_line`. Kinds used by the tested flow include `payment`, `refund`, and `transfer`.
