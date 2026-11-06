# API security audit

Date: 7 November 2026. Scope: the 54 `/api/v1` routes in `Marketplace\Platform\Api\RouteMap`, the PrestaShop front controller `marketplaceplatform/controllers/front/entry.php`, stock reservation, and payment state. The live host was not changed. This is not a third-party penetration test.

Production readiness is **not** claimed. The verdict and blockers are in `docs/PRODUCTION_READINESS_REPORT.md`.

## What was verified

| Check | Result |
| --- | --- |
| PHPUnit | OK. 73 tests, 765 assertions. PHP 8.3.33, PHPUnit 11.5.57, `php -d extension=pdo_sqlite`. Time 30.871 seconds |
| Anonymous access | Every protected route returns `401 unauthorized` with an empty body |
| MySQL last-unit race | Two PHP processes, database `marketplace_race`. Result `{"results":["NO","OK"],"orders":1}`. Database dropped after the run |
| Shop HTTP boundary | `POST` with `text/plain` returned `415 unsupported_media_type`. Malformed JSON returned `400 invalid_json`. Health returned the security headers below |
| Composer lock | `php composer.phar audit` reported no advisories. The lock contains PHPUnit only. PrestaShop image packages were **NOT TESTED** |
| Secrets in the contract | `ContractDocumentTest` rejects `DemoAdmin123`, `HarborDemo123`, and a test PAN in OpenAPI and Postman |

Health response headers from `http://localhost:8080/api/v1/health`:

```text
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Content-Security-Policy: default-src 'none'
Cache-Control: no-store
X-Request-Id: 03dc0e6a735badf6
```

## Issue register

### S1. Paid stock could be released after 30 minutes

| Field | Detail |
| --- | --- |
| Severity | Critical |
| Module | `Platform::releaseExpired`, checkout and payment |
| Root cause | Expiry released every active reservation, including holds for orders already paid |
| Risk | The same unit could be sold twice after the hold window |
| Fix | The expiry query skips parent orders in `paid`, `partially_refunded`, or `refunded` |
| Regression test | `SecurityRegressionTest::testPaidReservationSurvivesExpiryAndBlocksASecondSale` |
| Verification | Passed. A second checkout of the last unit returns 409 and the released count is 0 |
| Limitation | Fulfilled lines are consumed by fulfillment, not by this query. A partial refund does not restock |

### S2. Payment could succeed after the hold expired

| Field | Detail |
| --- | --- |
| Severity | High |
| Module | `PlatformFinance::pay`, webhook `SUCCEEDED` |
| Root cause | The provider was called without confirming the reservation still existed |
| Risk | A charge with no stock, or a webhook marking an order paid after the unit was sold |
| Fix | `ensureReservations` runs before the provider call. A successful webhook that cannot reserve sets settlement status `stock_conflict`, leaves the order unpaid, and defers the webhook |
| Regression test | `SecurityRegressionTest::testPaymentAfterTheHoldExpiresDoesNotChargeWithoutStock` |
| Verification | Passed against `MemoryGateway`. The first shopper receives `409 insufficient_stock` and the order stays unpaid |
| Limitation | No live Mangopay call. Provider verification remains **BLOCKED** |

### S3. A failed pay-in kept the last unit locked

| Field | Detail |
| --- | --- |
| Severity | High |
| Module | `PlatformFinance` failed pay-in and failed webhook |
| Root cause | `FAILED` updated the order and left the reservation `active` |
| Risk | Stock stayed unsellable for the rest of the hold, or until a later expiry bug released a paid order |
| Fix | `releaseOrderStock` runs on a failed pay-in and on a failed webhook when the order is not already paid |
| Regression test | `SecurityRegressionTest::testFailedPaymentReleasesTheLastUnit` |
| Verification | Passed. Stock 1, failed card, second shopper checkout returns 201 |
| Limitation | Tested with the memory gateway card alias `card_fail` |

### S4. Tokens could not be revoked

| Field | Detail |
| --- | --- |
| Severity | High |
| Module | `POST /api/v1/auth/logout`, `suspendVendor` |
| Root cause | Credentials lived until the 30-day expiry. Suspension did not delete them |
| Risk | A stolen token or a suspended vendor kept calling the API |
| Fix | Logout deletes the presented token hash. Suspension deletes that vendor's credential rows. `authenticate` re-reads vendor status |
| Regression test | `SecurityRegressionTest::testLogoutAndSuspensionRevokeAccess` |
| Verification | Passed. Logout then `GET /vendors/me` is 401. After suspension the old token is 401 |
| Limitation | There is no API password reset and no session list. A token that is never logged out remains valid until expiry. Storefront reset is a different flow and was not retested here |

### S5. Registration could send protected fields

| Field | Detail |
| --- | --- |
| Severity | High |
| Module | `Payload`, vendor registration and profile update |
| Root cause | Callers could add `commission_percent` or `status` beside accepted fields |
| Risk | A vendor could change commission or activate itself if a handler copied unknown input |
| Fix | `Payload::check` rejects unknown fields with `422 unexpected_field`. Commission is set only by `POST /admin/vendors/{id}/commission` |
| Regression test | `SecurityRegressionTest::testVendorCannotAssignCommissionOrReadAnotherVendorsExport` |
| Verification | Passed. Commission stays `10.00` and status stays pending until approval |
| Limitation | `Payload` checks presence, JSON type, nullability, and nested cart keys. String length and numeric ranges still live in the domain methods |

### S6. Offer and order CSV cells could be formulas

| Field | Detail |
| --- | --- |
| Severity | Medium |
| Module | `Platform::exportOffers`, `Platform::exportOrders` |
| Root cause | Text cells were written as supplied |
| Risk | A title beginning with `=` can execute as a formula in a spreadsheet |
| Fix | Cells matching `^[=+\-@\t\r]` are prefixed with a single quote |
| Regression test | The same vendor-isolation test exports a title `=1+1` and expects `'=1+1` |
| Verification | Passed |
| Limitation | Applied to those two exports, not to every future CSV writer |

### S7. API responses lacked a request id and browser hardening headers

| Field | Detail |
| --- | --- |
| Severity | Medium |
| Module | `HttpGuard`, `entry.php` |
| Root cause | The front controller returned JSON without `X-Request-Id` or framing headers |
| Risk | Harder incident tracing. JSON responses could be framed or sniffed by a careless client |
| Fix | `nosniff`, `DENY`, `no-referrer`, `Content-Security-Policy: default-src 'none'`, `no-store`, and `X-Request-Id` |
| Regression test | `SecurityRegressionTest::testMalformedJsonAndPathParametersAreRejected` asserts the header map. Shop curl confirmed the live headers |
| Verification | Passed |
| Limitation | These headers are on `/api/v1`, not on the HTML storefront |

### S8. Bad JSON and the wrong content type were not rejected at the boundary

| Field | Detail |
| --- | --- |
| Severity | Medium |
| Module | `HttpGuard::jsonBody` |
| Root cause | Body parsing depended on the caller |
| Risk | A non-JSON body could become an empty command or a 500 with a parser message |
| Fix | Non-empty POST, PUT, and PATCH bodies must be `application/json`. Invalid JSON returns `400 invalid_json` and the message is the stable envelope |
| Regression test | Unit assertions plus shop curls: `415` for `text/plain`, `400` for `{` |
| Verification | Passed on `http://localhost:8080` |
| Limitation | `ApiServer` tests call the service directly and do not pass through `HttpGuard` unless the test invokes it |

### S9. Rate-limit rows were kept forever

| Field | Detail |
| --- | --- |
| Severity | Medium |
| Module | `Platform::runMaintenance` |
| Root cause | Every call inserted `marketplace_api_hit` and nothing deleted it |
| Risk | Unbounded table growth |
| Fix | Maintenance deletes rows older than 86400 seconds |
| Regression test | `SecurityRegressionTest::testMaintenanceDropsExpiredRateLimitRowsAndKeepsRecentOnes` |
| Verification | Passed. 120 expired hashes stop counting. 120 fresh hashes still trip the limit |
| Limitation | Deletion happens when cron or `POST /admin/maintenance` runs, not on each request |

### S10. Import and reconciliation lists were unbounded

| Field | Detail |
| --- | --- |
| Severity | Medium |
| Module | `listImports`, `reconciliation` |
| Root cause | Both queries selected the vendor or global history with no limit |
| Risk | A large vendor history could be returned in one response |
| Fix | Imports are `LIMIT 100`. Reconciliation is `ORDER BY id DESC LIMIT 200` |
| Regression test | None that inserts more than the cap |
| Verification | Code inspection only |
| Limitation | The caps are not proven by a row-count assertion |

### S11. Deadlock retry has no failing-lock test

| Field | Detail |
| --- | --- |
| Severity | Medium |
| Module | `Store::transaction` |
| Root cause | Concurrent InnoDB updates can raise `SQLSTATE 40001` |
| Risk | A checkout could fail once instead of retrying |
| Fix | The transaction retries a deadlock up to three attempts and does not retry `PlatformException` |
| Regression test | None. A deadlock was not forced |
| Verification | **NOT TESTED** |
| Limitation | The two-connection stock race passed without needing a retry |

### S12. Customer identity is split

| Field | Detail |
| --- | --- |
| Severity | High as a launch requirement. Not an injection bug |
| Module | `marketplace_shopper` and `ps_customer` |
| Root cause | The API and the storefront were built as separate accounts |
| Risk | One person can have two passwords, two order histories, and no shared authorization |
| Fix | Not implemented. The safe strategy is in `docs/PRODUCTION_READINESS_REPORT.md` |
| Regression test | None for a link column, because the column was not added |
| Verification | **NOT IMPLEMENTED** |
| Limitation | API checkout still does not create `ps_orders` |

### S13. Mangopay sandbox is not configured

| Field | Detail |
| --- | --- |
| Severity | High operational blocker |
| Module | `HttpGateway`, health |
| Root cause | `MARKETPLACE_MANGOPAY_CLIENT_ID` and `MARKETPLACE_MANGOPAY_API_KEY` are absent |
| Risk | Real cards, refunds, and payouts cannot be verified. A missing credential fails closed with `503 mangopay_not_configured` |
| Fix | None in this pass. `live_provider_verified` stays false |
| Regression test | `HttpGatewayTest` inspects the request shape with a fake HTTP client |
| Verification | **BLOCKED**. See `docs/PAYMENT_SECURITY_REPORT.md` |
| Limitation | Memory-gateway tests are not live provider verification |

## Accepted behaviour that was not changed

- A wallet transfer that fails after a successful pay-in leaves the order paid and settlement `pending_transfer`. Reconciliation records it. Unpaying the order would hide a captured charge.
- The webhook route is public. Authenticity is the provider re-fetch of `EventType` and `RessourceId`, not an HMAC shared with this shop.
- MySQL allows multiple rows whose `provider_id` is NULL under the unique `(provider, provider_id)` key.
- Bearer tokens are not cookies, so the API does not add a CSRF token. Storefront forms still use the PrestaShop token.
- SQL from the platform goes through `Store` prepared statements. Sort fields are allow-listed. That review did not include a dynamic scan of PrestaShop core.

## OWASP API checks

| Topic | Result |
| --- | --- |
| Broken object level authorization | Shopper mismatch is 404. Vendor mismatch is 403. Covered for orders, invoices, and exports. See `docs/VENDOR_ISOLATION_AUDIT.md` |
| Broken authentication | Password hash, dummy verify, logout, suspension. No API password reset |
| Broken object property authorization | Unknown fields rejected. Commission and status are not on the public schemas |
| Unrestricted resource consumption | 120 requests per 60 seconds, except health. CSV 2 MB. PDF 5 MB. Page size clamped to 100. Hit rows pruned by maintenance |
| Broken function level authorization | Admin routes require the admin role. The anonymous matrix test covers every protected route |
| Sensitive business flows | Checkout, pay, refund, and payout require an idempotency key. Stock uses a conditional update |
| Server-side request forgery | No URL fetch was added for invoices or imports. Image URLs are stored, not retrieved by this audit's code path |
| Security misconfiguration | API headers added. Redis in Compose has no password and no published port. See `docs/REDIS_AND_QUEUE_ARCHITECTURE.md` |
| Inventory management | Composer lock has no advisories. The PrestaShop image was not scanned |
| Unsafe consumption of APIs | Mangopay responses are not treated as success unless the gateway returns a known status. Live calls were not made |

Path parameters must match `^[1-9][0-9]{0,9}$` or the server returns `422` before authentication. `GET /api/v1/orders/not-a-number` is covered by the regression test.
