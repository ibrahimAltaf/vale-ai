# Backend architecture

The shop is PrestaShop 9.2 with Hummingbird. Marketplace behaviour lives in proprietary modules. PrestaShop core is not edited. The original catalog, shipping, optimizer, and commission services stay the calculation engines. The platform module adds persistence, payments, invoices, and `/api/v1`.

```text
Storefront controllers
        |
        v
marketplacecore services + repositories
        |
        +--> CartOptimizer, ShippingCalculator, CommissionCalculator, ProductMatcher
        |
marketplaceplatform
        |
        +--> Platform / PlatformFinance
        +--> Store (PDO, prepared statements, transactions)
        +--> Mangopay Gateway (HTTP, memory, or unavailable)
        +--> ApiServer + RouteMap
        +--> Schema installer
```

## Module boundary

`marketplacecore` owns the demo schema, money helpers, and the storefront order repository. `marketplaceplatform` owns the versioned API and the tables added for payments, KYC, payouts, webhooks, invoices, API tokens, and import schedules. `SchemaInstaller::upgrade()` applies both. New statements are `CREATE TABLE IF NOT EXISTS` or `ALTER TABLE ... ADD`, and a duplicate-column or duplicate-key error is ignored. Upgrade does not drop or reseed demo data.

## Two order paths

| Path | Customer | Native `ps_orders` | Stock |
| --- | --- | --- | --- |
| Storefront checkout and payment | PrestaShop customer | One native order per vendor shipment, inserted by `PrestaShopOrderGateway` | `PrestaShopStockAccount` for the native path; marketplace reservations in `confirmLines` |
| `POST /api/v1/checkout` | `marketplace_shopper` | Not created | Conditional `UPDATE` on `marketplace_vendor_stock`, plus `SELECT ... FOR UPDATE` on MySQL |

API checkout leaves `id_customer` null and sets `id_marketplace_shopper`. Storefront orders leave the shopper column null.

## Money

Amounts are integer cents. Commission and discounts use basis points, half up: `intdiv(amount * bps + 5000, 10000)` in `Marketplace\Core\Domain\Money`. Commission on a sale is a percentage of the vendor product subtotal. Shipping is not commissioned. Refunds compute the fee on the positive product portion and store negative ledger rows.

## Processes

- Web request: PrestaShop front controller `marketplaceplatform` / `entry`.
- Cron: `bin/marketplace-cron.php` calls `Platform::runMaintenance`, which releases expired reservations and runs due CSV schedules.
- Tests: PHPUnit on the host. Domain tests do not boot PrestaShop. Platform tests open SQLite through PDO. The host `php.ini` does not enable `pdo_sqlite`; the documented command loads it with `-d extension=pdo_sqlite`.

## What this design does not do

It does not replace the storefront optimizer UI. It does not call the official Mangopay SDK, because that would add a dependency the repository does not already use. The HTTP client speaks the documented REST paths. It does not mark `live_provider_verified` true without a recorded sandbox call.
