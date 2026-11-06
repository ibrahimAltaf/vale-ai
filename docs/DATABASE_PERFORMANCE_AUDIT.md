# Database performance audit

Date: 7 November 2026. Engine: MySQL 8.0 in `pristashop-db-1`. The shop database `prestashop` was not loaded, truncated, or used for the scale seed. Demo parent orders were counted before the bench and were still 4. Throwaway databases `marketplace_bench` and `marketplace_race` were created, measured, and dropped.

## Scale fixture

`modules/marketplaceplatform/bin/bench-scale.php marketplace_bench` refused to run when the database name was `prestashop`. The fixture that was measured:

| Item | Value |
| --- | --- |
| Vendors | 30 |
| Master product ids | 4000, overlapping. Every vendor offers every product |
| Offers | 120000 |
| Stock rows | 120000 |
| Shipping | One rule per vendor, base 300 cents, free threshold 5000, surcharge 100 cents per kg |
| Peak PHP memory | 2 MB for the measurement process |

Seeding used batched transactions of 2000 offer and stock inserts. The seed completed. A first measurement attempt then failed because `OFFSET ?` is not a valid native prepared placeholder. The script now inlines the integer offset. The numbers below are from the rerun, which did not reseed.

## EXPLAIN

Product offer lookup for `id_product = 2500`:

| Table | type | key | rows | Extra |
| --- | --- | --- | --- | --- |
| offer `o` | ref | `mp_offer_product` | 30 | Using where |
| vendor `v` | eq_ref | PRIMARY | 1 | Using where |
| stock `s` | eq_ref | `mp_stock_offer` | 1 | |

`mp_offer_product_active` was a possible key and was not chosen. The estimate `filtered 10%` is the optimizer's statistic, not the returned count. The PHP loop received 30 candidates.

Catalog page `ORDER BY price_cents, id LIMIT 20 OFFSET 100000`:

| type | key | rows | Extra |
| --- | --- | --- | --- |
| index | `mp_offer_product_active` | 119753 | Using where; Using index; Using filesort |

## Timings

These are means, not percentiles. The script divides total time by the number of runs.

| Workload | Runs | Total | Mean |
| --- | --- | --- | --- |
| Offer lookup by product id, 30 candidates | 100 | 0.246 seconds | 2.46 ms |
| Shallow pages, offset 0 through 980 | 50 | 5.275 seconds | 105.5 ms |
| Deep page, offset 100000 | 20 | 1.659 seconds | 82.9 ms |
| Cart optimize, 2 lines, 30 offers each | 20 | 3.461 seconds | 173.1 ms |

The optimizer status on that cart was `OPTIMAL_VERIFIED`, grand total 3505 cents, search space 13950. That status is the optimizer's own bound. An independent exhaustive proof for this 13950-combination cart was not run. See `docs/SMART_CART_CORRECTNESS_REPORT.md`.

The catalog target of p95 under 500 ms is met by these means, with the filesort still visible. A price-order index was not added. The measured page is already inside the target, and another secondary index would slow the 120,000-row import path the bench just wrote.

## Reservation index

`mp_reservation_expiry` on `marketplace_stock_reservation (status, expires_at)` is in `mysql.sql` and `sqlite.sql`. `SHOW INDEX` on the shop database `prestashop` already listed that index. No `ALTER` was required and no demo row was modified.

## Concurrency

`modules/marketplaceplatform/bin/mysql-race.php marketplace_race` started two PHP processes inside `pristashop-prestashop-1`. Both called checkout for product 77, quantity 1, against one unit of stock. Output:

```json
{"results":["NO","OK"],"orders":1}
```

SQLite `StockRaceTest` remains the in-suite two-process test. It is not a substitute for the MySQL result above.

`Store` retries InnoDB deadlocks up to three times. This race did not raise a deadlock, so that retry path is **NOT TESTED**.

## Queries that stay bounded

| Query | Bound |
| --- | --- |
| Import job list | `LIMIT 100` |
| Reconciliation list | `LIMIT 200` newest |
| API list pages | `per_page` at most 100 |
| Rate-limit hits | Deleted after one day when maintenance runs |

No N+1 trace of the PrestaShop back office was captured. The offer lookup above is one prepared statement returning the vendor's candidates for a product, which is the access the optimizer needs.
