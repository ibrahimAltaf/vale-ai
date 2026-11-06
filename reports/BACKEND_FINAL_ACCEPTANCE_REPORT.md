# Backend final acceptance report

Date: 7 November 2026. Shop: local Docker PrestaShop 9.2 at `http://localhost:8080`. The live host `191.215.35.197` was not changed.

Later the same day, `docs/PRODUCTION_READINESS_REPORT.md` replaced this verdict. The route count is 54, the suite is larger, and production readiness is still not claimed.

## Verdict

The marketplace backend described in the module scope is implemented and the automated suite is green. It is not production-ready for real card payments. Mangopay sandbox credentials are absent, `live_provider_verified` is hard-coded false, and no call was made to `api.sandbox.mangopay.com`.

Of the 30 checklist rows in `docs/BACKEND_ACCEPTANCE_CHECKLIST.md`:

| State | Count |
| --- | --- |
| Tested in this run | 20 |
| Implemented, with a narrower test than the whole behaviour | 5 |
| Blocked on an external credential | 1 |
| Not done | 3 |
| Known boundary, not a missing API feature | 1 |

The five narrower items are vendor suspend as its own case, the profile field whitelist, an API-level ambiguous-match import, the fulfill happy path, and a cron run that actually expires a reservation. Approve, CSV retry, and `releaseExpired` on an empty set were exercised.

## Tests

Command:

```text
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

| Item | Value |
| --- | --- |
| PHP | 8.3.33 |
| PHPUnit | 11.5.57 |
| Result | OK |
| Tests | 63 |
| Assertions | 600 |
| Time | 01:29.721 |
| Memory | 10.00 MB |
| Exit code | 0 |

The earlier suite of 45 tests and 176 assertions is included. Eighteen tests were added. `pdo_sqlite` is not enabled in the host `php.ini`. The DLL is loaded with `-d extension=pdo_sqlite`. Without that flag the SQLite tests skip, and that skipped run is not this acceptance result.

`php -l` reported no syntax errors on the platform module, the new tests, and the `bin` scripts. PHPStan is not installed and was not added.

Optimizer: 200 breakfast scenarios stayed `OPTIMAL_VERIFIED` at 5200 cents. A timed run took 0.283 seconds. The PHPUnit bound is 5 seconds. A 120,000-offer load was not run.

## MySQL

Local demo database `prestashop` after `docker compose up -d` with `PS_ERASE_DB=0`:

| Reference | Grand cents |
| --- | --- |
| 1001 | 5800 |
| 1002 | 5800 |
| 1003 | 520 |
| 1004 | 690 |

Those rows were still present after the platform module install and two cron runs. Offer count before the install was 42.

A separate database, `marketplace_test`, was created, loaded from `install.sql` and `modules/marketplaceplatform/sql/mysql.sql`, used for one checkout, payment, and partial refund, then dropped. The script printed:

```text
{"checkout":201,"grand_cents":10000,"payment":201,"refund":"partially_refunded","available_cents":6750}
```

The gateway in that script was in-memory. It proves the MySQL SQL, including integer stock binding and commission rounding. It does not prove a Mangopay sandbox payment.

## Local HTTP and cron

Marketplace Platform was installed into the running shop. PrestaShop's `.htaccess` sends `/api` to the webservice, which returned `401` until the module wrote an `/api/v1` exception above the generated block.

| Call | Result |
| --- | --- |
| `GET /api/v1/health` | `200`, `payment_mode=unconfigured`, `live_provider_verified=false` |
| `GET /api/v1/offers` | `401 unauthorized` |
| `POST /api/v1/auth/login` for the API admin | `503 admin_not_configured` |
| Cron, first and second run | `{"released_reservations":0,"imports":[]}` |

The first cron attempt failed because the script is mounted at `/usr/local/bin` and looked for `/usr/local/vendor/autoload.php`. It now falls back to `modules/marketplacecore/src/Autoload.php`. The successful runs above are after that fix.

## API inventory

53 routes under `/api/v1`, generated from `RouteMap` into `docs/openapi.yaml` and `postman/PrestaShop_Marketplace_API.postman_collection.json`. `ContractDocumentTest` checks that every route is in both files.

| Tag | Routes |
| --- | --- |
| Health | 1 |
| Authentication | 3 |
| Vendors | 2 |
| Shipping | 2 |
| Catalog | 1 |
| Offers | 2 |
| Inventory | 2 |
| Smart Cart | 2 |
| Checkout | 1 |
| Orders | 5 |
| Payments | 2 |
| Mangopay | 4 |
| Refunds | 1 |
| Payouts | 3 |
| Commissions | 1 |
| Admin | 8 |
| CSV Imports | 5 |
| Invoices | 8 |

The method, path, role, and permission for each route are in `docs/API_REFERENCE.md`. Financial POSTs require `Idempotency-Key`. A succeeded pay-in returns `201`. A pending provider or a deferred transfer returns `202`. A failed pay-in returns `402` and writes no commission.

API customers are `marketplace_shopper` rows. API checkout does not insert `ps_orders`. The storefront gateway still does that for a PrestaShop customer.

## Database

`SHOW TABLES LIKE 'ps_marketplace_%'` on the local shop returned 35 tables. The platform install added, among others, `marketplace_shopper`, `marketplace_payment`, `marketplace_mangopay_user`, `marketplace_mangopay_wallet`, `marketplace_refund`, `marketplace_payout`, `marketplace_webhook_event`, `marketplace_reconciliation_line`, `marketplace_import_schedule`, `marketplace_invoice`, `marketplace_invoice_file`, `marketplace_audit_log`, `marketplace_api_credential`, `marketplace_api_permission`, `marketplace_idempotency`, `marketplace_api_hit`, `marketplace_order_event`, and `marketplace_master_product`.

Upgrade also adds nullable `id_marketplace_shopper` on the parent order, `fulfillment_status` on the vendor order, and the offer index `(id_product, active, price_cents)`. Statements are `CREATE TABLE IF NOT EXISTS` or `ALTER`, and duplicate-column errors are ignored. The installer does not truncate demo data.

## Security

Reviewed in `docs/SECURITY_AUDIT.md`. Controls covered by tests: prepared statements, card-field rejection, webhook fetch-and-reject, payment idempotency, the conditional stock update, shopper 404, vendor 403, and PDF path checks. Residual items: rate-limit rows are not pruned, a transfer failure after a successful pay-in leaves the order paid with `settlement_status=pending_transfer`, and the webhook route is public because authenticity is the provider fetch. No Mangopay secret was committed.

## Mangopay

**BLOCKED.** `MARKETPLACE_MANGOPAY_CLIENT_ID` and `MARKETPLACE_MANGOPAY_API_KEY` are not set. The shop health payload says `unconfigured`. `HttpGatewayTest` checks the HTTP shape against a fake transport. Memory-gateway payments in PHPUnit and in the throwaway MySQL script are not provider verification.

## Postman

| File | State |
| --- | --- |
| `postman/PrestaShop_Marketplace_API.postman_collection.json` | 53 requests, one folder per tag, status and envelope tests on each request |
| `postman/PrestaShop_Local.postman_environment.json` | `http://localhost:8080`, empty token |
| `postman/PrestaShop_Staging.postman_environment.json` | `https://staging.example.invalid` |

No demo back-office password and no card number are in those files. Newman was not run. The three shop HTTP calls above are the live checks.

## Performance

| Measurement | Result |
| --- | --- |
| 200 breakfast optimizations | 0.283 seconds, grand total 5200 cents, `OPTIMAL_VERIFIED` |
| Two-process last-unit race | One `OK`, one `NO`, one order |
| 120,000 offers | Not loaded |

## Documentation

| File | Present |
| --- | --- |
| `docs/openapi.yaml` | Yes |
| `docs/API_REFERENCE.md` | Yes |
| `docs/AUTHENTICATION.md` | Yes |
| `docs/ERROR_CODES.md` | Yes |
| `docs/WEBHOOKS.md` | Yes |
| `docs/BACKEND_ARCHITECTURE.md` | Yes |
| `docs/DATABASE_SCHEMA.md` | Yes |
| `docs/DATABASE_ERD.md` | Yes |
| `docs/MODULE_INVENTORY.md` | Yes |
| `docs/MANGOPAY_INTEGRATION.md` | Yes |
| `docs/PAYMENT_AND_REFUND_FLOWS.md` | Yes |
| `docs/VENDOR_ONBOARDING.md` | Yes |
| `docs/SMART_CART_OPTIMIZER.md` | Yes |
| `docs/STOCK_RESERVATION.md` | Yes |
| `docs/CSV_IMPORT_AND_CRON.md` | Yes |
| `docs/INVOICING.md` | Yes |
| `docs/SECURITY_AUDIT.md` | Yes |
| `docs/TESTING_REPORT.md` | Yes |
| `docs/DEPLOYMENT_GUIDE.md` | Yes |
| `docs/CPANEL_DEPLOYMENT.md` | Yes |
| `docs/DOCKER_DEPLOYMENT.md` | Yes |
| `docs/ENVIRONMENT_VARIABLES.md` | Yes |
| `docs/BACKEND_ACCEPTANCE_CHECKLIST.md` | Yes |

Regenerate the OpenAPI file and the Postman collection with `php bin/export-api-contract.php` after any route change.

## External dependencies still open

- Mangopay sandbox client id, API key, platform wallet id, and payout bank account id.
- A recorded sandbox pay-in, refund, payout, and webhook before `live_provider_verified` can change.
- cPanel was not used. The cron command was run in the local container only.
- No electronic-invoicing provider is connected.
- The API and the storefront still use different customer records.

## Production readiness

Do not take live payments on this build. Do install the module and the `/api/v1` rewrite on a shop that should expose the API. Keep `PS_ERASE_DB=0` on the demo database. The local demo orders listed above were not removed.
