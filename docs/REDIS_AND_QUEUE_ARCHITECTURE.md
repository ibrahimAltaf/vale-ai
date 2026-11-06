# Redis and queue architecture

Date: 7 November 2026.

## Decision

Marketplace stock, balances, reservations, and payment state stay in MySQL. Redis was not added as a cache or lock for those records. The bench did not show a catalog or optimizer latency that Redis would have to fix: a product lookup on 120,000 offers averaged 2.46 ms, and a two-line optimization averaged 173 ms. Adding Redis in front of those reads would create a new invalidation path without a measured miss.

`docker-compose.yml` already runs `redis:7-alpine` for PrestaShop. `REDIS_HOST` and `REDIS_PORT` are set on the shop container. The service has no password and no published host port. Marketplace cron does not enqueue to Redis.

## What uses MySQL instead

| Concern | Store |
| --- | --- |
| Rate limit | `marketplace_api_hit`, 120 calls in 60 seconds. Maintenance deletes rows older than one day |
| Idempotency | `marketplace_idempotency` |
| Stock reservation | `marketplace_stock_reservation` plus a conditional update |
| Payment and commission | `marketplace_payment`, `marketplace_commission`, `marketplace_refund`, `marketplace_payout` |
| CSV schedules | `marketplace_import_schedule`, executed by `bin/marketplace-cron.php` and `POST /api/v1/admin/maintenance` |
| Optimization | Computed on request. Results are not cached |

## Queue behaviour that exists

Cron is a PHP process, not a supervised worker fleet. `runMaintenance` releases eligible expired reservations, prunes rate-limit rows, and runs import schedules whose interval has elapsed. A failed import is stored on the import job row. There is no separate dead-letter topic. Two overlapping cron processes can both see a due schedule. The import writer is scoped to one vendor and updates by vendor plus SKU, so a double run updates the same offers rather than another vendor's rows. That overlap was not load-tested. Call it **NOT TESTED** under concurrent cron.

CSV imports reject a row whose vendor code does not match the authenticated vendor. Ambiguous EAN or SKU matches fail the row and do not merge products. Exports prefix formula-like cells. The 2 MB cap remains.

## Production constraints, not changed here

| Item | State |
| --- | --- |
| Redis authentication | **NOT CONFIGURED**. The Compose service has no `requirepass` |
| Redis network | No host port. Other containers on the Compose network can still connect |
| Worker restart policy | The shop and database use Compose defaults. No marketplace worker service was added |
| Cache invalidation | Not applicable to marketplace prices or stock, because those reads are not cached in Redis |

Do not point financial code at Redis later unless a measured query is slower than these bench numbers and MySQL remains the commit path.
