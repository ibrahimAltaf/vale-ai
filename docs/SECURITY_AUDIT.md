# Security audit

Review date: 7 November 2026. Scope: `marketplaceplatform` and the payment, stock, and API paths it adds. This is a code review plus the automated tests named below. It is not a third-party penetration test.

## Controls in place

| Risk | Control |
| --- | --- |
| SQL injection | `Store` uses prepared statements. Table and column names are allow-listed with a character class. Sort columns and directions are allow-listed |
| Raw card data | `CardPayload` rejects PAN and CVV field names. Only a card alias is stored |
| Webhook forgery | The resource is fetched from Mangopay. A missing resource is `401`. Duplicate `(event_type, resource_id)` does not double-post commission |
| Duplicate payment | Unique idempotency key on payments and a conditional status update before commission insert |
| Oversell | Conditional stock update inside `BEGIN IMMEDIATE` or a MySQL row lock. Two-process test expects one winner |
| IDOR | Shopper mismatch on an order or customer invoice is `404`. Vendor mismatch is `403` |
| Mass assignment | Vendor profile update has an explicit field list. Registration cannot set commission or status |
| File upload | PDF magic bytes, 5 MB cap, generated file name, `realpath` jail, mode `0600` |
| Credential leakage | API admin password is a hash. Mangopay key is read from the environment. `500` responses omit exception text. Health never claims live verification |
| CSRF on the API | Bearer tokens, not cookies. Storefront forms still use the PrestaShop `token` field |
| Rate limit | 120 requests per minute, health excluded |
| Access control | Route roles plus `marketplace_api_permission` |

## Findings

1. **Live Mangopay is not configured.** Missing credentials fail closed. This is safe and it is also a launch blocker for real payments. Severity: operational, not an injection bug.
2. **API and storefront identities are separate.** A PrestaShop customer session does not authorize `/api/v1`. Documented so the next client does not assume one login covers both.
3. **Transfer failure after a successful pay-in leaves the order paid.** The money is audited as `pending_transfer`. An operator must retry settlement. This is intentional and must stay visible in reconciliation.
4. **MySQL unique provider id does not collapse NULL provider ids.** Pending payments can exist without a provider id. Succeeded payments store one.
5. **Rate-limit retention is one day and runs only from maintenance.** `runMaintenance` deletes `marketplace_api_hit` rows older than 86400 seconds. Rows remain until cron runs.
6. **No PHPStan or Psalm in the repository.** Static analysis for this change is `php -l`. That does not find every type bug.
7. **Webhook endpoint is public by design.** Protection is the provider fetch, not a network allow-list. Put the shop behind HTTPS before pointing Mangopay at it.
8. **Invoice download checks the vendor id and the shopper's order.** `assertInvoiceAccess` returns only for an administrator, the owning vendor, or the shopper on a customer invoice for their order. Everyone else receives 404. The flow test covers another shopper. A second vendor download is covered by the same test.

No hardcoded Mangopay secret was added. The demo back-office password remains in the existing README and compose file for the local demo only.

## Regression tests

`MarketplaceFlowTest` covers declined payments, webhook replay, idempotency conflict, vendor and shopper isolation, raw card rejection, SQL-like postcode input, invoice isolation, and the second-checkout stock rejection. `StockRaceTest` covers two SQLite processes. `SecurityRegressionTest` covers anonymous access to every protected route, paid-hold survival, payment after expiry, failed-payment release, logout, suspension, unknown commission fields, CSV formula prefixes, and rate-limit retention. `HttpGatewayTest` checks that the pay-in body has `CardId` and no CVV field, and that the API key is not an HTTP header.

The 7 November security pass is recorded in `docs/API_SECURITY_AUDIT.md`. This file remains the shorter control list.
