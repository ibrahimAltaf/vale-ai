# PrestaShop marketplace demo — test results

Audit date: 7 November 2026.

This file records the only automated test run performed for this audit. No shop, database, or VPS was changed.

## Environment

| Item | Value |
| --- | --- |
| Working directory | `c:\Users\muham\Desktop\pristashop` |
| Host PHP | 8.3.33 |
| PHPUnit | 11.5.57 |
| Configuration | `phpunit.xml.dist` |
| Bootstrap | `vendor/autoload.php` |
| Suite | `tests/` |
| Docker shop | Not started and not queried |
| Live host | Not requested |

The Docker image in `docker-compose.yml` is `prestashop/prestashop:9.2.0-8.4`. That image was not part of this test run. The suite does not boot PrestaShop.

## Command

```text
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

## Result

```text
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.33
Configuration: C:\Users\muham\Desktop\pristashop\phpunit.xml.dist

.............................................                     45 / 45 (100%)

Time: 00:04.699, Memory: 8.00 MB

OK (45 tests, 176 assertions)
```

| Outcome | Count |
| --- | --- |
| Passed | 45 |
| Failed | 0 |
| Skipped | 0 |
| Incomplete | 0 |
| Risky | 0 |
| Assertions | 176 |
| Exit code | 0 |

An older note of 38 tests and 158 assertions does not match this repository. The current suite is 45 tests and 176 assertions, counted by this execution.

## Tests that ran

| File | Tests | What they assert |
| --- | --- | --- |
| `tests/Optimizer/CartOptimizerTest.php` | 7 | Oats split 2+4, grand 5800 cents. Breakfast Alpine-only, product 5200, shipping 0, grand 5200, against a 5500 unit-price baseline. Coffee split 5+1, grand 7420. No-stock infeasible. Postcode 80331 still splits oats. A zero combination budget returns `FALLBACK_NOT_PROVEN_OPTIMAL`. Weight surcharge changes the winning vendor. |
| `tests/Shipping/ShippingCalculatorTest.php` | 7 | Base fee, free-shipping threshold including the boundary, charge below the threshold, weight surcharge, waiver of the weight charge at the threshold, postcode prefixes, empty prefix list. |
| `tests/Shipping/ShippingRuleSelectorTest.php` | 4 | Longer prefix beats a nationwide rule. Nationwide rule remains at the demo postcode. An unmatched vendor keeps its only rule so the quote can reject the postcode. Munich weight is charged below the free threshold. |
| `tests/Matching/ProductMatcherTest.php` | 6 | Exact EAN, exact SKU, no match, duplicate EAN not merged, EAN and SKU pointing at different products, duplicate SKU. |
| `tests/Catalog/OfferImporterTest.php` | 5 | Update by vendor and SKU, update by vendor and EAN, ambiguous row rejected, no match plans a new master product, exact EAN plans an offer. |
| `tests/Catalog/OfferComparisonTest.php` | 2 | Coffee cheapest unit and cheapest delivered price are reported separately. Those two winners can differ. |
| `tests/Orders/StockReservationTest.php` | 4 | Reserve reduces available stock. Oversell is rejected. A failed second reserve does not consume the first offer. An expired reservation is released before a new reserve. |
| `tests/Orders/OrderSplitterTest.php` | 1 | A parent order splits into vendor sub-orders with commission. |
| `tests/Orders/CardPaymentTest.php` | 3 | A valid card returns only the last four digits. A bad number is rejected. An expired card is rejected. |
| `tests/Commission/CommissionCalculatorTest.php` | 3 | 10% of 10000 cents is 1000 commission and 9000 net. Demo vendor rates. Half-up rounding. |
| `tests/Collections/CollectionScheduleTest.php` | 3 | Demo windows on the review day. Inclusive boundaries. A disabled collection stays disabled inside its window. |

`tests/Support/ArrayStockAccount.php` is a test double, not a test case.

## What this suite does not cover

These areas have no test files. They were not executed, and they are not reported as passed.

| Area | Result |
| --- | --- |
| Stock reservation against MySQL, including two concurrent transactions | Not run. `StockReservationTest` uses `ArrayStockAccount`. `PrestaShopStockAccount::lock()` contains `SELECT ... FOR UPDATE`, but that adapter is not loaded by PHPUnit. |
| Optimizer performance or timing | Not run. `testFallbackDoesNotClaimGlobalOptimality` forces the fallback by setting the combination budget to 0. It does not measure the 200000 default. |
| Checkout, payment page, or `ps_orders` insert | Not run. |
| Mangopay, refunds, payouts, webhooks | No code and no tests. |
| CSV export, cron, invoice PDF | No code and no tests. |
| REST or mobile API | No code and no tests. |
| Templates, controllers, hooks, Smarty, admin screens | Not run. |
| Access control beyond the pure services | Not run. There is no security test suite. |
| `MarketplaceApp`, `DemoSeeder`, `SchemaInstaller` | Not run. |

## Failures and blocked tests

There were no failures and no skipped tests.

The shop-level flows are blocked from automated verification in this run because the suite is pure PHP and PrestaShop was not booted. That is an environment limit of the suite, not a failed test.
