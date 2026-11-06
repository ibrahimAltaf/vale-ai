# Load test report

Date: 7 November 2026. Targets below were set before measurement. A mean or a small sample is not reported as a pass against a p95 target unless the sample really is a percentile.

## Targets

| Workload | Target |
| --- | --- |
| Simple reads | p95 under 300 ms |
| Indexed catalog search | p95 under 500 ms |
| Ordinary writes | p95 under 700 ms |
| One optimization | under 5 seconds |

External payment-provider time is excluded. No Mangopay call was made.

## Shop HTTP, through PrestaShop

Twenty requests each, `curl.exe` time_total against `http://localhost:8080`. Sorted sample, index at 50% and 95% of the 20 values.

| Route | p50 | p95 | max |
| --- | --- | --- | --- |
| GET `/api/v1/health` | 0.408 s | 0.737 s | 1.964 s |
| GET `/api/v1/offers` (401, no token) | 0.353 s | 0.965 s | 0.967 s |

**Result: FAILED against the 300 ms simple-read target.** The samples include PrestaShop front-controller startup. They are not catalog-query time.

## API handler without the storefront

`bench-api.php` on throwaway database `marketplace_race`, 30 samples, inside the shop container. Sorted indexes 14 and 28 of 30.

| Call | HTTP | p50 | p95 | max |
| --- | --- | --- | --- | --- |
| Health | 200 | 0.038 ms | 0.086 ms | 0.175 ms |
| Offer list | 200 | 9.45 ms | 49.17 ms | 55.90 ms |
| Vendor profile | 200 | 16.59 ms | 100.08 ms | 137.67 ms |
| Admin vendor list | 200 | 10.22 ms | 54.56 ms | 59.84 ms |
| Vendor orders | 200 | 25.74 ms | 67.95 ms | 86.31 ms |
| Login missing a field | 422 | 0.044 ms | 0.117 ms | 9.45 ms |

**Result: these handler samples meet the read target.** The database had one vendor and one order, so this is not the 120,000-offer catalog. Writes were not sampled 30 times. Checkout concurrency is the race below, not a latency percentile.

## 120,000 offers

Database `marketplace_bench`, then dropped. Means from `bench-scale.php`:

| Workload | Mean | Target |
| --- | --- | --- |
| Product offer lookup, 30 of 120000 rows | 2.46 ms | Indexed catalog p95 500 ms. This figure is a mean. It is far under the target and is not a p95 |
| Page offset 0–980 | 105.5 ms | Same caveat |
| Page offset 100000 | 82.9 ms | Same caveat. EXPLAIN shows an index scan and filesort |
| Optimize 2 lines × 30 offers | 173.1 ms | Under 5 seconds |

EXPLAIN details and the decision not to add a price index are in `docs/DATABASE_PERFORMANCE_AUDIT.md`.

CPU, memory of the MySQL process, and concurrent shopper load during the import were **NOT TESTED**. The PHP bench process peaked at 2 MB. No second client browsed the shop while the 120,000 rows were inserted.

## Concurrency

| Test | Environment | Result |
| --- | --- | --- |
| Two checkouts, one unit | MySQL `marketplace_race`, two PHP processes | `{"results":["NO","OK"],"orders":1}` |
| Two checkouts, one unit | SQLite `StockRaceTest` | Part of the 73-test suite, passed |
| Deadlock retry | MySQL | **NOT TESTED**. The race did not deadlock |
| CSV import while customers browse | — | **NOT TESTED** |
| Simultaneous optimizations beyond 20 sequential calls | Container PHP | **NOT TESTED** |

## Newman timing

Newman against `http://127.0.0.1:8099` (disposable SQLite, not the shop) reported average 145 ms, minimum 109 ms, maximum 340 ms, over 54 requests in 12.7 seconds. That average mixes 401 responses and a few writes. It is not a p95 catalog benchmark. See the status mix in `docs/PRODUCTION_READINESS_REPORT.md`.
