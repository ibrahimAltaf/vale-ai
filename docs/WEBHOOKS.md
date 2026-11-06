# Webhooks

Endpoint: `POST /api/v1/mangopay/webhooks`

The route is public. Authenticity is not a shared HMAC on the body. Mangopay's supported check is to read `EventType` and `RessourceId` (Mangopay's spelling) and fetch that resource with the API credential. If the fetch returns nothing, the call is stored as rejected and the response is `401 webhook_unverified`.

## Idempotency

`marketplace_webhook_event` has a unique key on `(event_type, resource_id)`. The second delivery of the same pair returns `duplicate: true` and does not write a second commission.

A payment is marked paid with a conditional update that matches only a row whose status is not already `succeeded`. A repeated success notification cannot insert a second positive commission row.

## Pay-in results

| Provider status | Order | Commission |
| --- | --- | --- |
| `SUCCEEDED` | `payment_status=paid` | One positive product commission per vendor sub-order, then wallet transfers |
| `FAILED` | `payment_status=failed` | None |
| Other | Payment stays pending (`202` on the original pay call) | None until a later verified success |

A transfer failure after a succeeded pay-in does not reverse the payment. The order stays paid and `settlement_status` becomes `pending_transfer`. That line is audited. It is not treated as a failed payment.

## Retry

The handler is safe to retry. Provider calls that create pay-ins, refunds, and payouts send `Idempotency-Key`. The API key is not written to the audit log.

## Live verification

`GET /api/v1/health` reports `payment_mode` as `memory`, `sandbox`, or `unconfigured`. `provider_configured` is true only when the mode is `sandbox`. `live_provider_verified` is hard-coded `false`. A mocked or recorded HTTP test does not change that flag. See [MANGOPAY_INTEGRATION.md](MANGOPAY_INTEGRATION.md).
