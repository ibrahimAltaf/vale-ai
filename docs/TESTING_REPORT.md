# Testing report

Date: 7 November 2026. Host PHP 8.3.33. PHPUnit 11.5.57. Configuration: `phpunit.xml.dist`. The host `php.ini` does not load `pdo_sqlite`. The suite command loads that extension for one process. `ext/php_pdo_sqlite.dll` is present in the WinGet PHP build.

## Command and result

```text
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

```text
OK (63 tests, 600 assertions)
Time: 01:29.721
Memory: 10.00 MB
Exit code: 0
```

The previous full run, before this backend, was 45 tests and 176 assertions. Those tests are still in the suite. The 18 added tests are the platform flow, HTTP gateway shape, optimizer timing, two-process stock race, and the OpenAPI/Postman contract check. 45 + 18 = 63.

Without `-d extension=pdo_sqlite`, `MarketplaceFlowTest` and `StockRaceTest` skip. The other tests still run. Do not treat a skipped run as the acceptance run.

## What the new tests execute

| Test | Evidence |
| --- | --- |
| `MarketplaceFlowTest` (14) | Pending vendor login, breakfast grand total 5200, Munich postcode quote 450, fallback not claimed optimal, failed pay-in writes no commission, refund 2500 leaves available balance 6750, webhook duplicate does not double commission, checkout idempotency, vendor and shopper isolation, raw card rejection, CSV retry, invoice isolation including a second vendor, second checkout of the last unit, rate limit |
| `StockRaceTest` | Two PHP processes, one SQLite file, results `NO` and `OK`, exactly one order |
| `HttpGatewayTest` | Pay-in URL `/payins/card/direct`, `CardId` present, no CVV field, bearer token, idempotency header, API key not sent as a header. The transport is fake |
| `OptimizerPerformanceTest` | 200 breakfast optimizations stay `OPTIMAL_VERIFIED` at 5200 cents and finish in under 5 seconds |
| `ContractDocumentTest` | All 53 routes appear in `docs/openapi.yaml` and the Postman collection. No demo passwords or a test PAN |

A separate timing of the same 200 breakfast iterations, outside PHPUnit, took 0.283 seconds.

## MySQL

The local Docker database `prestashop` was not used for the financial scenario. A throwaway database `marketplace_test` was created, loaded with `install.sql` and `modules/marketplaceplatform/sql/mysql.sql`, and then dropped.

The script `var/mysql-flow.php` ran inside `pristashop-prestashop-1` and printed:

```text
{"checkout":201,"grand_cents":10000,"payment":201,"refund":"partially_refunded","available_cents":6750}
```

That is the 10 percent commission case: sale net 9000, partial refund 2500, available balance 6750. The payment gateway was `MemoryGateway`, not Mangopay.

After that database was dropped, demo orders were still:

| Reference | Grand cents |
| --- | --- |
| 1001 | 5800 |
| 1002 | 5800 |
| 1003 | 520 |
| 1004 | 690 |

## Local shop HTTP

The stack was started with `docker compose up -d` and `PS_ERASE_DB=0`. Marketplace Platform was installed. PrestaShop's `/api` webservice rule was exempted for `/api/v1`.

| Call | Result |
| --- | --- |
| `GET /api/v1/health` | `200` `payment_mode=unconfigured`, `provider_configured=false`, `live_provider_verified=false` |
| `GET /api/v1/offers` | `401 unauthorized` |
| `POST /api/v1/auth/login` as admin | `503 admin_not_configured` |
| `php /usr/local/bin/marketplace-cron.php` twice | `{"released_reservations":0,"imports":[]}` both times |

The Postman collection was not executed with Newman. Newman is not installed. The collection's route coverage is the contract test above, plus these three HTTP calls.

## Static analysis

`php -l` reported no syntax errors for every PHP file under `modules/marketplaceplatform`, `tests/Platform`, `tests/Concurrency`, and `bin`. The repository has no PHPStan or Psalm dependency, so none was added.

## Not claimed

No call was made to `api.sandbox.mangopay.com`. `live_provider_verified` stays false. The 120,000-offer catalog was not loaded. The storefront breakfast page was not clicked in a browser during this run. The demo order rows above are the database evidence that those orders were not deleted.
