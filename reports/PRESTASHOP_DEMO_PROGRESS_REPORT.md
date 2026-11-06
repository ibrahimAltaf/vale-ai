# PrestaShop 9 marketplace demo — technical progress report

Audit date: 7 November 2026  
Repository: `c:\Users\muham\Desktop\pristashop`  
Public source: `https://github.com/ibrahimAltaf/vale-ai`

This audit inspected the code and executed the PHPUnit suite. It did not change application code, database rows, or the deployed shop. It did not open a browser and did not call the live host.

## How to read the statuses

| Status | Meaning in this report |
| --- | --- |
| COMPLETE | The behavior exists in code and a passing automated test in this audit asserts it. |
| PARTIAL | Some of the behavior exists. A named part is missing, or the shop screen was not executed in this audit. |
| NOT STARTED | No meaningful implementation was found. |
| BLOCKED | Could not be judged because a dependency or environment was missing. None of the 22 items are in this state. |

“Implemented” and “verified by the 7 November test run” are not the same thing. The test run does not boot PrestaShop, so controllers, Smarty templates, and SQL adapters were not executed.

## 1. Architecture

### Platform

| Item | Evidence |
| --- | --- |
| PrestaShop target | Modules declare `ps_versions_compliancy` `9.0.0`–`9.99.99`. Example: `modules/marketplacecore/marketplacecore.php`. |
| Pinned image | `prestashop/prestashop:9.2.0-8.4` in `docker-compose.yml`. |
| PHP for tests | 8.3.33 on the host. `composer.json` requires PHP `>=8.2`. |
| Database | MySQL 8.0 service in `docker-compose.yml`. |
| Cache | Redis 7. `modules/marketplacecore/src/Cache/RedisClient.php`. Optional. Optimizer still runs if Redis is down. |
| Theme | Hummingbird is the shop theme installed by the official image. Marketplace pages are Smarty module templates, not a separate React application. |
| Admin charts | React 18 and Recharts are loaded from a CDN in `modules/marketplacecore/views/templates/admin/nav.tpl` and mounted by `modules/marketplacecore/views/js/charts.js`. |
| Money | Integer cents. Commission uses basis points, half up, in `CommissionCalculator`. |

There is no migration framework. Schema is `modules/marketplacecore/sql/install.sql`, applied by `SchemaInstaller::install()`. Later columns and the password-reset table are added by `SchemaInstaller::upgrade()`.

### Modules

All nine live under `modules/`. Version on `marketplacecore` is `1.0.0`.

| Module | Role |
| --- | --- |
| `marketplacecore` | Schema, seed, repositories, `MarketplaceApp`, storefront shell, routes |
| `marketplacevendor` | Vendor admin screen and vendor front dashboard |
| `marketplacecatalog` | Catalog page, product offers, CSV import |
| `marketplaceshipping` | Shipping calculator and rule selector |
| `marketplaceoptimizer` | Smart Cart |
| `marketplaceorders` | Reservations, split orders, payment, account, receipt, mail |
| `marketplacecommission` | Gross, commission, net |
| `marketplaceanalytics` | Admin dashboard |
| `marketplacecollections` | Dated collections |

`Marketplace\Core\Application\MarketplaceApp` is the composition root used by controllers. Domain services do not call PrestaShop. Repositories are the SQL boundary.

### Tables

Prefix in a shop is the PrestaShop prefix. The SQL file uses `PREFIX_`.

| Table | Relationship |
| --- | --- |
| `marketplace_vendor` | Root vendor row. Unique code, slug, and email. |
| `marketplace_shipping_rule` | FK to vendor. Base, free threshold, cents per kg, postcode prefixes. |
| `marketplace_vendor_offer` | FK to vendor. `id_product` is not a foreign key to `ps_product`. Unique `(vendor, vendor_sku)`. |
| `marketplace_vendor_stock` | FK to offer. On-hand, reserved, low-stock threshold. |
| `marketplace_product_match` | Match audit. `id_product` nullable. |
| `marketplace_collection` / `marketplace_collection_product` | Date window and member products. |
| `marketplace_cart` / `marketplace_cart_line` | Demo cart, separate from the native PrestaShop cart. |
| `marketplace_order` | Parent order. Customer and payment columns are in the SQL and also added by `upgrade()`. |
| `marketplace_vendor_order` / `marketplace_vendor_order_line` | Sub-order such as `1001-A`. Optional `id_order` points at a native shop order. |
| `marketplace_stock_reservation` | Active, consumed, or released, with expiry. |
| `marketplace_commission` | Gross, commission, and net per vendor order. |
| `marketplace_settlement` | Open monthly totals. Not a payout. |
| `marketplace_import_job` | CSV import log. |
| `marketplace_password_reset` | Vendor reset token hash. Created in `SchemaInstaller::upgrade()`. |

### Controllers and routes

Front routes are registered in `MarketplaceCore::hookModuleRoutes()`:

| Path | Controller |
| --- | --- |
| `marketplace/cart` | `marketplaceoptimizer` / `cart` |
| `marketplace/catalog` | `marketplacecatalog` / `catalog` |
| `marketplace/collections` | `marketplacecollections` / `list` |
| `marketplace/vendor` | `marketplacevendor` / `dashboard` |
| `marketplace/vendor/password` | `marketplacevendor` / `password` |
| `marketplace/account` | `marketplaceorders` / `account` |
| `marketplace/pay` | `marketplaceorders` / `payment` |
| `marketplace/order/{reference}` | `marketplaceorders` / `receipt` |

Admin tabs, created by `TabInstaller`:

| Class | Screen |
| --- | --- |
| `AdminMarketplace` | Parent menu |
| `AdminMarketplaceDashboard` | Overview |
| `AdminMarketplaceVendors` | Status and commission edit |
| `AdminMarketplaceImport` | CSV upload |
| `AdminMarketplaceOrders` | Order list |
| `AdminMarketplaceCollections` | Collections |

Hooks on `marketplacecore`: `displayHeader`, `displayNav1`, `displayTop`, `displayHome`, `moduleRoutes`, `actionFrontControllerInitBefore`, and, when missing, `displayAfterBodyOpeningTag`, `displayFooterBefore`, `displayBeforeBodyClosingTag`. `marketplacecatalog` hooks `displayProductAdditionalInfo`.

`leaveNativeStorefront()` redirects native cart, order, category, search, and listing pages into the marketplace. It does not redirect the product controller, because marketplace offers render there.

### Integrations

| Integration | Finding |
| --- | --- |
| PrestaShop customers | `ShopperAccount` creates a `Customer`, hashes the password with the core hasher, and logs the customer in. |
| Native orders | `PrestaShopOrderGateway` inserts `ps_orders` with SQL. It does not call `PaymentModule::validateOrder`. One shop order per vendor shipment. |
| Mail | `OrderMailer` calls PrestaShop `Mail::Send` and returns false on failure. |
| Card data | `CardPayment` runs Luhn and expiry checks and returns the last four digits. The full number is not stored. |
| Mangopay | No class, config, or dependency. |
| REST / webservice | No module front API, no API Platform, no OpenAPI file. |
| Cron | No crontab, console command, or scheduled module. |

### Deployment files

| File | Use |
| --- | --- |
| `docker-compose.yml` | Local shop on port 8080 |
| `docker-compose.vale.yml` | Shop container `vale-ai` on an existing reverse proxy, without publishing host 80 or 443 |
| `docker/apache-limits.conf` | Raises Apache’s header size so a large PrestaShop cookie is not rejected |
| `docker/nginx-prestashop.conf` | Sample Nginx site for a non-Docker install |
| `bin/install-modules.php` | Installs the nine modules after PrestaShop exists |

## 2. Feature audit

### 1. Vendor registration, onboarding, and management — PARTIAL

Admin can update status and commission in `AdminMarketplaceVendorsController::postProcess()` through `MarketplaceApp::updateVendor()`. Vendors themselves are inserted by `DemoSeeder` from `DemoCatalog`. There is no public registration form, no document upload, and no approval queue.

Vendor password reset exists: `modules/marketplacevendor/controllers/front/password.php` and table `marketplace_password_reset`.

Verified in this audit: no. The controller was read, not executed.

### 2. Vendor roles, permissions, and authentication — PARTIAL

Vendor login is a marketplace password. `MarketplacevendorDashboardModuleFrontController` loads the vendor by email, checks `password_verify`, and rejects a status other than `active`. The session is cookie keys `mp_vendor` and `mp_vendor_hash`.

There are no vendor roles, no staff accounts, and no permission matrix. Back-office access is a normal PrestaShop employee. Shoppers are PrestaShop customers, separate from vendors.

Verified in this audit: no automated test covers login.

### 3. Product catalog and product matching — COMPLETE

`ProductMatcher` returns exact, no match, or ambiguous. Ambiguous rows are not merged. Six tests in `tests/Matching/ProductMatcherTest.php` passed.

The storefront catalog is `modules/marketplacecatalog/controllers/front/catalog.php` and `views/templates/front/catalog.tpl`. The product hook always renders `offers.tpl`, including when a product has no vendor offer.

The catalog page itself was not opened in this audit.

### 4. Multiple vendor offers per SKU — COMPLETE

`marketplace_vendor_offer` allows many vendors on one `id_product`, with one SKU per vendor. `tests/Catalog/OfferComparisonTest.php` passed and checks that the cheapest unit and the cheapest delivered offer are reported separately.

### 5. Vendor-specific pricing and inventory — COMPLETE

Each offer has `price_cents`, `discount_percent`, and `weight_grams`. Stock is `quantity` and `reserved_quantity` on `marketplace_vendor_stock`. Comparison and reservation tests passed.

There is no multi-warehouse model and no separate fulfillment decrement after a reservation is consumed.

### 6. CSV imports and exports — PARTIAL

Import is implemented. `CsvOfferParser` reads the file. `OfferImporter::plan()` decides update, create, or reject. `MarketplaceApp::importCsv()` writes the plan and stores an import job as completed. Five tests in `tests/Catalog/OfferImporterTest.php` passed. The admin screen is `AdminMarketplaceImportController`. Sample file: `modules/marketplacecatalog/data/sample-offers.csv`.

No export method or download action was found.

The import writer that talks to PrestaShop was not executed by PHPUnit. The tests cover the planner.

### 7. Scheduled vendor CSV cron jobs — NOT STARTED

`OfferImporter` says a later cron could run the same plan. The demo writes the job as completed immediately. No scheduler, command, or `pending` worker was found.

### 8. Stock reservation and concurrency handling — PARTIAL

`StockReservationService::reserve()` sorts offer ids, locks, rejects an oversell, and releases expired holds first. `PrestaShopStockAccount::lock()` uses `SELECT ... FOR UPDATE` and must run inside `Connection::transaction()`. `MarketplaceApp::confirmLines()` is the caller that opens that transaction.

`tests/Orders/StockReservationTest.php` passed, using `ArrayStockAccount`, not MySQL. No two-process concurrency test was run. The row lock was not executed in this audit.

### 9. Order optimizer and quantity splitting — COMPLETE

`CartOptimizer` prices product cost plus shipping. It returns `OPTIMAL_VERIFIED` only when the combination space is within the budget (`200000` by default) and every combination was priced. Above that budget the status is `FALLBACK_NOT_PROVEN_OPTIMAL`. An impossible cart is `INFEASIBLE`.

Seven tests in `tests/Optimizer/CartOptimizerTest.php` passed, including the oats 2+4 split at 5800 cents, the breakfast result at 5200 cents, the coffee split at 7420 cents, the infeasible oats case, and the forced fallback.

No timing or load test was run. The fallback test sets the budget to 0 so it does not measure the real 200000 cap.

### 10. Postcode and weight-based shipping — COMPLETE

`ShippingCalculator` charges base plus `grams * surcharge per kg / 1000`, and returns ineligible when the postcode does not match. `ShippingRuleSelector` lets a longer matching prefix beat a nationwide rule.

`tests/Shipping/ShippingCalculatorTest.php` (7) and `tests/Shipping/ShippingRuleSelectorTest.php` (4) passed.

### 11. Free-shipping thresholds and fallback rules — COMPLETE

The calculator sets shipping to 0 at or above `free_threshold_cents`, including the weight surcharge. The optimizer fallback is the separate rule in feature 9. Both are covered by the passing shipping and optimizer tests.

The primary demo rules stay at EUR 0 per kg so the published 10115 totals stay stable. Harbor’s extra Munich rule is prefix 80 at EUR 1.50 per kg, added by `DemoCatalog::extraShippingRules()` and `SchemaInstaller::upgrade()`.

### 12. Customer checkout integration — PARTIAL

The flow is the marketplace cart, then `marketplace/pay`, then `MarketplaceApp::confirmLines()` with a checkout array. Pay requires a logged-in customer. Card and bank transfer are the two methods. `CardPayment` is a local check. `PrestaShopOrderGateway` writes native orders. `OrderMailer` tries to send mail and does not fail the order when mail fails.

This is not PrestaShop’s standard checkout and not a payment provider. Three card tests passed. The payment controller and the SQL gateway were not executed in this audit.

### 13. Vendor-specific order splitting — COMPLETE

`OrderSplitter` builds one parent and one sub-order per vendor, with commission on the product subtotal. `tests/Orders/OrderSplitterTest.php` passed.

`confirmLines()` persists those rows and then asks the gateway for one `ps_orders` row per shipment. That persistence path is not in the test suite.

### 14. Commission calculation and management — PARTIAL

`CommissionCalculator` is tested. Ten percent of 10000 cents is 1000 commission and 9000 net (`testTenPercentOfOneHundredEuros`). Shipping is not commissioned.

Admin can change the percent on the vendor screen. `OrderRepository` updates `marketplace_settlement` for the open month. There is no approval step, no statement PDF, and no payout.

### 15. Mangopay payment integration — NOT STARTED

Search of the repository found no Mangopay class, package, key, or webhook.

### 16. Refunds, payouts, webhooks, and reconciliation — NOT STARTED

No refund service, payout service, webhook controller, or reconciliation report was found. The settlement table only accumulates gross, commission, and net.

### 17. Vendor invoice management and PDF uploads — NOT STARTED

No upload field, stored file, or vendor invoice table was found.

### 18. Customer invoice downloads — NOT STARTED

No customer download action was found. `PrestaShopOrderGateway` fills `invoice_date` on the native order row. That is not a downloadable marketplace invoice.

### 19. Vendor dashboard — PARTIAL

`modules/marketplacevendor/views/templates/front/dashboard.tpl` is a full page: login, logout, that vendor’s summary, orders, and low stock. Data comes from `DashboardRepository::vendorSummary()` and the vendor’s own orders.

It was not opened in this audit and it has no PHPUnit coverage. It is demonstration-ready from the code, not re-verified on 7 November.

### 20. Super Admin dashboard and marketplace analytics — PARTIAL

`AdminMarketplaceDashboardController` assigns `MarketplaceApp::adminDashboard()`. The template shows GMV, orders, commission, vendors, offers, four charts, recent orders, and settlement rows. Chart values are JSON from `salesSeries()`, `commissionSeries()`, and `ordersByVendor()`. Month-over-month captions come from `periodTrend()`, which runs SQL. They are not hardcoded percentages.

The sidebar entries Commissions, Settlements, and Analytics are anchors on this page, not separate tools. Settings links to the module configuration screen.

There is no date-range filter, export, or cohort analysis. The page was not opened in this audit. It is presentable as a demo overview. It is not a production analytics product.

### 21. REST APIs and mobile-ready endpoints — NOT STARTED

No API routes were found. The only routes are the Smarty module pages listed above.

### 22. Security, validation, and error handling — PARTIAL

Present:

- Marketplace forms check `token` against `Tools::getToken(false)` before login and cart changes.
- Customer passwords go through PrestaShop length and score checks. `ShopperAccount` hashes before `Customer::add`.
- Card PAN and CVC are not stored.
- Vendor reset stores a hash, not the raw token, on `marketplace_password_reset`.
- Domain services throw on bad quantities, bad cards, and insufficient stock.

Absent:

- No security test suite was run.
- No rate limit, 2FA, audit log, or vendor permission model.
- Demo passwords are in `README.md` and `docker-compose.yml`.
- Native orders are inserted with SQL rather than the core payment API.
- This audit did not include a penetration test.

## 3. Demo readiness

These can be demonstrated from the code and the passing tests. Screens marked “not re-opened” were not clicked on 7 November.

| Feature | Where | Data | Limit |
| --- | --- | --- | --- |
| Delivered-price homepage | `/` via `hookDisplayHome` | Seed | Brand is placeholder. Not re-opened. |
| Catalog and offers | `/marketplace/catalog`, product hook | Seed | Sample clothes can remain in the database. |
| Smart Cart totals | `/marketplace/cart` | Seed scenarios in `DemoCatalog::scenarioQuantities()` | Totals are verified by PHPUnit, not by a browser in this audit. |
| CSV import screen | Admin Offer import | Sample CSV plus planner tests | Export does not exist. The PrestaShop writer was not executed here. |
| Vendor login and dashboard | `/marketplace/vendor` | Seeded vendors | No self-registration. Not re-opened. |
| Admin overview | `AdminMarketplaceDashboard` | Live SQL totals once orders exist | Charts need the CDN scripts. Not re-opened. |
| Commission edit | `AdminMarketplaceVendors` | Seed | Edit only. Not re-opened. |
| Collections | `/marketplace/collections` and admin | Seed windows | Schedule math is tested. The page was not re-opened. |
| Checkout | `/marketplace/pay` | A customer the presenter registers | Simulated card. Mail may fail. |

Broken or incomplete for a client who expects production:

- Mangopay, refunds, payouts, webhooks, invoices, cron, CSV export, and REST are absent, not hidden behind a flag.
- Finance and Settings in the admin sidebar do not open dedicated applications.
- The admin avatar is the letter M in `nav.tpl`. It is not a user menu.
- Checkout cannot be finished as a guest.
- Refreshing the demo catalog resets seeded reservations. It does not delete orders.

## 4. UI and UX

### Customer shop

Implemented templates: `home.tpl`, `nav.tpl`, `footer.tpl`, `product-card.tpl`, `catalog.tpl`, `offers.tpl`, `cart.tpl`, `result.tpl`, `list.tpl`, `payment.tpl`, `account.tpl`, `receipt.tpl`.

Styling is project CSS plus Tailwind from a CDN (`marketplacecore.php`). This audit did not resize a browser, so responsive behavior is not reported as verified. Known structural choices in the CSS: layout grids need a base column class because the Hummingbird theme forces a 12-column grid, and product offers are a single visible list rather than a table that the theme can override.

Native PrestaShop cart and category pages are redirected. A product with no vendor offer shows “not sold by a marketplace vendor” instead of the theme buy box.

### Vendor portal

One front controller and `dashboard.tpl`, plus `password.tpl`. There is no registration page, no product editor, and no invoice page.

### Admin

The custom shell hides the PrestaShop menu while `#mp-admin` is present. Pages that use it: dashboard, vendors, import, orders, collections.

Suitable for a demo meeting: yes, as an overview of this marketplace. Suitable as the production admin the client will operate daily: no. Settings, commissions, settlements, and analytics are sections of one dashboard, and several production tasks have no screen at all.

## 5. Remaining work

### CRITICAL — blocks a real marketplace of the kind this checklist describes

| Task | Current state | Missing | Done when |
| --- | --- | --- | --- |
| Real payment provider | `CardPayment` plus a SQL order insert | Mangopay or another PSP, capture, and failure handling that does not leave a paid marketplace order without a charge | A test payment is captured, stored, and visible on the order without keeping the full card number |
| Refunds and payouts | Settlement rows only | Refund API, vendor payout, webhook, and a reconciliation view | A refund and a payout each change the ledger and can be matched to a provider id |
| Vendor onboarding | Seed plus admin edit | Application, review, and activation without a developer | A new vendor can join and be approved from the back office |

### HIGH — required if the production list above is the contract

| Task | Current state | Missing | Done when |
| --- | --- | --- | --- |
| CSV export and schedule | Import planner and a completed job row | Export, `pending` job worker, cron | A file placed on a schedule updates offers without an admin upload, and a vendor can download their offers |
| Invoices | None | Vendor PDF upload, customer download | Each shipment has a file the vendor can replace and the customer can download |
| REST API | None | Versioned endpoints and auth | A documented client can read catalog and orders without scraping HTML |
| Checkout on the core payment API | Custom SQL in `PrestaShopOrderGateway` | `validateOrder` or an equivalent supported path, stock and order history included | A native order is created by the supported API and marketplace stock still cannot oversell |
| Mail | `OrderMailer` returns false when send fails | A working transport | Reset and order mail arrive in an inbox |

### MEDIUM

| Task | Current state | Missing | Done when |
| --- | --- | --- | --- |
| Concurrency proof | Row lock in the adapter, unit tests on a fake account | A MySQL test with two overlapping reserves | The second transaction cannot take stock the first transaction holds |
| Roles | One password per vendor | Staff users and permissions | A vendor employee can be limited to orders or catalog |
| Fulfillment | Reservation is consumed on confirm | On-hand decrement at shipment | Shipped units leave on-hand stock and cannot be reserved again |
| Admin information architecture | Anchors on one page | Real screens for settlements and settings | Each sidebar item opens the screen its label names |
| Images | URLs and theme image files | Import into PrestaShop image records | Product pages use shop image records, not only external URLs |

### LOW

| Task | Current state | Missing | Done when |
| --- | --- | --- | --- |
| Remove Hummingbird sample catalog | Redirects hide cart and categories | Delete or disable the sample products | A shopper cannot find the sample clothes |
| Demo secrets | Passwords in the README and Compose file | Environment-only secrets | A fresh clone has no published demo password |
| Brand | Prista Market, demo vendors, demo copy | Client name, logo, and catalog | The shop shows the client’s brand |
| Chart dependency | CDN React and Recharts | A pinned local build, if the CDN is not acceptable | Charts load without a third-party script host |

## 6. Completion estimate

The score is only for the 22 items in this report.

| Status | Items | Weight | Points |
| --- | --- | --- | --- |
| COMPLETE | 7 | 1 | 7 |
| PARTIAL | 9 | 0.5 | 4.5 |
| NOT STARTED | 6 | 0 | 0 |
| BLOCKED | 0 | — | 0 |
| Total | 22 |  | 11.5 |

11.5 / 22 = 52 percent.

Complete: 3, 4, 5, 9, 10, 11, 13.  
Partial: 1, 2, 6, 8, 12, 14, 19, 20, 22.  
Not started: 7, 15, 16, 17, 18, 21.

This percentage is not a measure of the 24-hour demo. That demo’s catalog, shipping, Smart Cart, split orders, and dashboards are largely present. The percentage drops because Mangopay, refunds, invoices, cron, and a REST API are not in the repository.

## 7. Production readiness

Not production ready.

A reviewer can be shown a seeded shop and the verified cart totals. A merchant cannot yet onboard, take a real payment, pay a vendor, issue an invoice, or run an unattended import.

## 8. Suggested order of work

1. Confirm whether Mangopay is actually required. If it is, it is greenfield.
2. Replace the simulated card with that provider, including webhooks and refunds.
3. Add vendor onboarding and payouts before any real seller is invited.
4. Add invoices and the scheduled CSV worker if those are in the contract.
5. Add an API only after the order and payment model has stopped changing.
6. Keep the current PHPUnit suite green while those pieces are added. It does not protect the templates, so a short browser pass of cart, vendor login, and admin import should be added to the release check.
