# Final backend completion report

The marketplace backend now covers customer linking, pallet freight, vendor minimum orders, commission VAT, credit notes, public vendor profiles, and stricter API validation. The automated suite is green. The shop is not production-ready: Mangopay has no sandbox credentials, the HTTP p95 target is still missed, and staging was not deployed.

Brand strings in the code remain Prista Market. Demo orders were not deleted. The shop database `prestashop` still has 4 marketplace orders after the additive schema update.

## Measured results

| Check | Result |
| --- | --- |
| API routes | 66 |
| PHPUnit | 81 tests, 921 assertions, OK |
| Newman workflow | 20 requests, 0 failed scripts |
| MySQL deadlock | `40001` observed, throwaway database dropped |
| Optimizer reference cases | PASS, including the €65 pallet case |
| 120,000-offer benchmark | PASS on a throwaway database that was then dropped |
| API latency target (p95 under 300 ms) | FAIL |
| Mangopay sandbox | BLOCKED |
| Staging deployment | BLOCKED |

PHPUnit command: `php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --do-not-cache-result`.

Newman command, against the disposable contract server on `127.0.0.1:8099`, not the demo shop:

`npx newman run postman/PrestaShop_Marketplace_Workflow.postman_collection.json -e postman/PrestaShop_Contract.postman_environment.json`

The workflow logged in seeded admin, vendor, shopper, and second-vendor accounts. Observed statuses included health 200, checkout 201, pay 201, partial refund 201, credit note 201, shopper invoice read 200, other-vendor invoice read 404, and shopper statement read 403. `live_provider_verified` stayed false. Three checks were registered as Newman assertions; the other status checks throw inside the same scripts, and none of those scripts failed.

## Latency

All samples are 40 requests from the host to `http://localhost:8080`. The adopted route is still the PrestaShop front controller (`index.php`). A root stub that includes `modules/marketplaceplatform/api.php` was measured and then removed from the live rewrite because it still boots `config.inc.php` and the offers samples did not improve.

| Path | Front | p50 ms | p95 ms | p99 ms |
| --- | --- | --- | --- | --- |
| `/api/v1/health` | front controller, first sample | 351.1 | 778.5 | 5506.7 |
| `/api/v1/health` | front controller, later sample | 559.7 | 1195.9 | 1722.6 |
| `/api/v1/offers` (401) | front controller | 293.9 | 489.5 | 545.3 |
| `/api/v1/health` | slim stub, not adopted | 173.5 | 357.0 | 603.1 |
| `/api/v1/offers` (401) | slim stub, first sample | 221.1 | 693.6 | 1070.4 |
| `/api/v1/offers` (401) | slim stub, second sample | 278.0 | 1463.2 | 3426.4 |

The earlier audit recorded health p95 737 ms and offers p95 965 ms. This session's offers sample on the front controller was 489.5 ms. The samples move around with the machine, and none of the p95 figures is under 300 ms. Status for the latency target: FAIL.

## 120,000-offer benchmark

Throwaway database `marketplace_scale`, created and dropped in this session. It was not the shop database. 30 vendors, 4,000 products, 120,000 offers. The product index used was `offer_product_index`. Peak memory for the measuring process was 2 MB.

The first run included the insert and a cold buffer pool. The second run used the same rows and is the warm measurement.

| Measurement | Cold, after insert | Warm rerun |
| --- | --- | --- |
| Lookup mean | 654.3 ms | 2.59 ms |
| Shallow page mean | 67.2 ms | 79.1 ms |
| Deep page mean, OFFSET 100000 | 86.6 ms | 260.3 ms |
| Optimizer mean, 2 lines, 30 offers each | 450.4 ms | 444.9 ms |
| Optimizer status | OPTIMAL_VERIFIED | OPTIMAL_VERIFIED |
| Grand total | 3505 cents | 3505 cents |
| Search space | 13950 | 13950 |

`OPTIMAL_VERIFIED` is returned only when the search space is within the configured combination budget (default 200000). This 13,950-combination case is inside that budget. A search that exceeds the budget returns `FALLBACK_NOT_PROVEN_OPTIMAL` and does not claim a global optimum. That fallback is covered by `OptimizerReferenceTest`.

## Mangopay

Sandbox credentials are not configured. `live_provider_verified` is hard-coded false. Memory-gateway tests are not live provider proof.

To run the live checks, set `MARKETPLACE_MANGOPAY_CLIENT_ID`, `MARKETPLACE_MANGOPAY_API_KEY`, `MARKETPLACE_MANGOPAY_PLATFORM_WALLET`, and the platform bank account, then record:

- a sandbox pay-in
- an idempotent replay of that pay-in
- a duplicate webhook
- a failed pay-in that releases stock
- a partial refund of 2500 cents on a 10000 cent order at 10 percent commission, leaving 6750 cents available
- a payout with KYC `VALIDATED`
- a transfer failure that stays `pending_transfer` and does not mark the order unpaid

Status: BLOCKED.

## Feature table

| Module / feature | Previous state | Work completed | Files modified | Database changes | Tests added | Test evidence | Remaining blockers | Final status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Customer linking | Separate `marketplace_shopper` and `ps_customer`, no confirmed link | Shopper requests a one-time code. An administrator confirms it. Email is not used. A customer id already linked to another shopper is rejected. | `modules/marketplaceplatform/src/Platform.php`, `src/Api/RouteMap.php`, `src/Api/ApiServer.php`, `src/Api/Payload.php` | `ps_marketplace_customer_link`, `ps_marketplace_shopper.id_customer`, unique `mp_shopper_customer` | `tests/Platform/CompletionTest.php` | Wrong code 403. Second shopper with a valid code for the same customer 409. Linked customer id is 42, not the shopper id. | None for the link itself | PASS |
| Native checkout order | API orders did not create `ps_orders` | After a succeeded payment, a linked shopper is passed to `PrestaShopOrderGateway` through `NativeOrderPort`. An unlinked shopper is not. A gateway failure is audited and does not unpay the marketplace order. | `src/NativeOrderPort.php`, `src/PrestaShopNativeOrders.php`, `controllers/front/entry.php`, `PlatformFinance.php` | `ps_marketplace_vendor_order.id_order` already existed and is now filled by the port | `CompletionTest` with a recording port | Linked pay stores native order id 900. Unlinked pay does not call the port. | A native row was not inserted into the demo shop, so the live `ps_orders` write was not exercised | NOT TESTED |
| Pallet freight | Parcel rules only. A free parcel threshold could be mistaken for free freight | Pallet base, pallet free threshold, weight and volume caps, pallet capacity, and pallet-only offers. Parcel free shipping does not zero a pallet price. | `modules/marketplaceshipping/src/Dto/ShippingRule.php`, `src/Service/ShippingCalculator.php`, `modules/marketplaceoptimizer/src/Dto/OfferOption.php`, `src/Service/CartOptimizer.php` | Shipping-rule pallet columns, offer `volume_cm3` and `pallet_only`. Defaults keep old rules on the parcel path | `tests/Platform/OptimizerReferenceTest.php` | Product 100000 cents with pallet-only freight 6500 costs 106500. A parcel alternative at the same product price costs 100000 with shipping 0 | None | PASS |
| Vendor minimum order | Not modeled | Each vendor can enable an amount or leave the rule disabled. A disabled rule does not filter the vendor. An enabled rule drops an allocation below the product subtotal. | `ShippingRule.php`, `CartOptimizer.php`, `Platform.php` | `min_order_enabled`, `min_order_cents`, default off and 0 | `OptimizerReferenceTest` | Enabled minimum excludes the cheap vendor. Disabled minimum allows a 1000 cent line | None | PASS |
| Smart-cart proof | Unit price, stock, and parcel shipping. Global optimum only inside the combination budget | Same budget rule. New costs include volume, pallet mode, and vendor minimums. Fallback text says the result is not a proven optimum. | `CartOptimizer.php`, `OptimizerReferenceTest.php` | None beyond the shipping columns | Existing fallback test plus the pallet and minimum cases | `OPTIMAL_VERIFIED` on the bounded cases. `maxCombinations = 0` stays `FALLBACK_NOT_PROVEN_OPTIMAL` | None | PASS |
| Commission VAT and statements | Product commission only. Shipping was already excluded | VAT is stored separately and defaults to 0, so the old 1000 / 9000 net example is unchanged. Monthly statement and CSV list gross, commission, VAT, processing fee, and net. Refunds store a negative VAT amount. | `PlatformFinance.php`, `Platform.php` | `vat_cents`, `vat_rate_percent`, `processing_fee_percent` | `CompletionTest` | 10000 cent sale at 10 percent and 22 percent VAT: commission 1000, VAT 220, net 9000 | None | PASS |
| Credit notes | Customer invoices only | A credit note requires a succeeded refund on that vendor shipment. Shoppers can read their own document. Another vendor receives 404. | `Platform.php`, `RouteMap.php` | Invoice `kind` already allowed the new value `credit_note` | `CompletionTest`, Newman workflow | Create 201, shopper read 200, other vendor 404. Missing refund is 409 in the domain | Italian SdI transmission was not built and is out of scope | PASS |
| Vendor profiles and catalog filter | Private vendor records | Public search by company name, public profile without email or phone, logo upload checks, and `vendor_ids` offer filter. One offer row still points at the master product id. | `Platform.php`, `RouteMap.php`, `Payload.php` | `city`, `brands`, `coverage`, `logo_path` | `CompletionTest`, Newman | Search returns Example Goods and does not include email. Offer filter stays inside the requested vendor ids | None | PASS |
| Password reset | Not in the API | Shopper and vendor can request a reset. Unknown email returns the same accepted body and no token. The token is stored as a hash. Confirm replaces the password and consumes the token. | `Platform.php`, `RouteMap.php` | `ps_marketplace_api_reset` | `CompletionTest` | Missing account has no token. Old password then returns 401. New password logs in | Mail delivery of the token is not wired. Tests expose the token only when the process flag is on | PASS |
| API payload and isolation | 54 routes, unknown fields already rejected | 66 routes. Length, range, boolean, and enum checks. Vendor statement and invoice reads stay on the caller. | `Payload.php`, `ApiServer.php`, `RouteMap.php`, `docs/openapi.yaml`, `postman/` | None | Existing security suite plus `CompletionTest` | Full PHPUnit file is green, including anonymous access and the new isolation cases | Generated collection still has envelope checks. The executed proof is the workflow collection | PASS |
| Stock conflict and full restock | Code existed, dedicated tests were thin | Pending pay-in can later be observed as succeeded. If the hold is gone, settlement becomes `stock_conflict` and the order stays unpaid. A full refund of an unfulfilled shipment releases the hold so another checkout can reserve it. | `PlatformFinance.php` behavior already present | None | `CompletionTest` | Webhook settlement `stock_conflict` and payment status `unpaid`. Second checkout after a full refund is `reserved` | None | PASS |
| MySQL deadlock retry signal | Not run on MySQL | Two connections lock rows in opposite order. One session receives SQLSTATE `40001`. The throwaway database is dropped. `Store::transaction` already retries that state up to three times. | `modules/marketplaceplatform/bin/mysql-deadlock.php` | Throwaway `marketplace_deadlock`, dropped | The script itself | `{"parent":"ok","child":"40001","saw_40001":true}` | The retry loop inside `Store` was not the process that caught this SQLSTATE | PASS |
| CSV formula injection | Offer and order export already prefixed risky cells | Statement and order numeric cells use the same prefix helper, so a leading `=`, `+`, `-`, or `@` is prefixed | `Platform.php` | None | Existing CSV test. Statement export is covered by the VAT test | VAT statement CSV contains the VAT amount. The prefix helper is the same one used by the passing offer export test | None | PASS |
| Redis cache | Client had no AUTH and no catalog version | Client sends AUTH when `REDIS_PASSWORD` is set. Offer lists cache for 30 seconds under a version key. Stock, price, shipping, vendor, reserve, and release changes increment the version. Money and payments are not cached. | `modules/marketplacecore/src/Cache/RedisClient.php`, `Platform.php` | None | No Redis in PHPUnit, so the client is a no-op there | Code review and the shop environment variable `REDIS_HOST`. No timed before/after for a Redis hit | `requirepass` is not enabled. Turning it on without the same password in PrestaShop's cache client would stall cache misses | NOT TESTED |
| Audit retention and log rotation | Audit rows were not aged out | Maintenance deletes audit rows older than 365 days. A logrotate snippet is in `docker/marketplace-logrotate.conf`. | `Platform.php`, `docker/marketplace-logrotate.conf` | Deletes only rows older than 365 days, and only when maintenance runs | None for the delete | The delete statement is in `runMaintenance`. It was not executed against aged MySQL rows. The logrotate file is not installed on the host | Retention job and host log rotation | NOT TESTED |
| Backup and restore | Not scripted | `bin/backup-marketplace.php` dumps named marketplace tables. Restore onto database `prestashop` exits with a refusal. A dump of that database also requires an explicit allow flag. The script does not delete rows. | `bin/backup-marketplace.php` | None | Refusal run | Command printed `Refusing to restore onto the shop database.` | A real dump and restore was not executed | NOT TESTED |
| Local Docker shop | Compose was already running | Schema changes are additive and were applied by a normal API connection. Health still returns 200. Security headers are unchanged. The latency target is a separate FAIL above. | `docker-compose.yml` was not changed. `api.php` exists but is not the live rewrite | ALTER columns listed above. No demo delete | Shop request and order count | Health HTTP 200. `SELECT COUNT(*) FROM ps_marketplace_order` returned 4 before and after | p95 remains over 300 ms | PASS |
| GitHub Actions | No workflow | `/.github/workflows/backend.yml` installs PHP 8.3 with `pdo_sqlite` and runs PHPUnit | `.github/workflows/backend.yml` | None | The workflow file | Not executed on GitHub in this session | A remote run | NOT TESTED |
| Staging and live host | Not authorized | No deploy. Staging URL in the contract remains `https://staging.example.invalid` | Postman staging environment | None | Contract document test | The test asserts that staging URL | No host access was provided | BLOCKED |
| Mangopay live provider | Fail closed without credentials | No rewrite of the gateway. Memory gateway can persist resources for the contract server only. Live flag stays false | `MemoryGateway.php`, `bin/contract-server.php` | None | Newman pay and refund against the memory gateway | Workflow pay 201 and refund 201. Health `live_provider_verified` false | Sandbox credentials listed above | BLOCKED |

## What was deliberately left unchanged

- PrestaShop core files were not edited.
- The live API rewrite remains `index.php` because the stub did not make offers faster.
- Redis `requirepass` stays off so the shop cache client does not wait on AUTH.
- Demo orders 1001–1004 were not modified.
- No secrets were added to Git. The contract password is the existing local placeholder `replace-with-12-plus`.

## Status key

PASS means the behavior was implemented and the cited test or measurement succeeded. FAIL means a target was measured and missed. BLOCKED means an external credential or authorization is missing. NOT TESTED means the code or script exists and this session did not produce the cited evidence.
