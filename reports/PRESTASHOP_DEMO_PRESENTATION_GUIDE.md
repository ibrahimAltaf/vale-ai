# Presenting the marketplace demo

7 November 2026

Use this walkthrough for the features that exist in the repository. Do not present Mangopay, invoices, payouts, scheduled imports, or a mobile API. Those are not in the code.

This audit ran the automated tests and did not click through a browser. The steps below follow the controllers, routes, and seed data. If a page does not match a step, stop and treat that screen as unverified.

## Before the meeting

1. Install Docker.
2. From the repository root:

```text
docker compose up -d
```

3. Wait until `http://localhost:8080` answers. The first start installs PrestaShop and can take several minutes.
4. Install the modules:

```text
docker compose exec prestashop php /usr/local/bin/install-marketplace.php
```

The command should end with `Marketplace demo is ready.`

5. If the web container has stopped after install:

```text
docker compose start prestashop
```

Shop: `http://localhost:8080`  
Back office: `http://localhost:8080/admin-dev/index.php`  
Currency in this stack: EUR. Language: English. Country: Germany.

Demo passwords below are the ones in `README.md` and `docker-compose.yml`. Change them before any public launch.

| Who | Email | Password |
| --- | --- | --- |
| Back office | `demo@marketplace.local` | `DemoAdmin123!` |
| Alpine Roast | `alpine@demo.local` | `AlpineDemo123!` |
| Harbor Bean | `harbor@demo.local` | `HarborDemo123!` |
| Northwind Pantry | `northwind@demo.local` | `NorthwindDemo123!` |

A fresh install does not create a shopper. Register one at `http://localhost:8080/marketplace/account` if you will show checkout. Use a long password. The shop rejects a short or common one.

## 1. Homepage

Open `http://localhost:8080`.

Show the marketplace header, the delivered-price story, and the product grid. The breakfast story on the page is EUR 55 against EUR 52, a EUR 3 difference. That story matches `tests/Optimizer/CartOptimizerTest.php` for `breakfast-shipping`.

Say that the brand on screen is a placeholder.

## 2. One product, three offers

Open Premium Coffee 1kg from the catalog, or search SKU `COFFEE-1KG`.

Expected on the product page, from the seed and from `tests/Catalog/OfferComparisonTest.php`:

- The same master product is sold by Alpine Roast, Harbor Bean, and Northwind Pantry.
- Shelf prices are EUR 12.00, EUR 11.20, and EUR 12.50.
- At postcode 10115 the delivered prices include shipping, so the cheapest shelf price is not automatically the cheapest delivered price.

The page is `modules/marketplacecatalog/views/templates/hook/offers.tpl`, rendered by `hookDisplayProductAdditionalInfo`.

## 3. Smart Cart — shipping beats the shelf price

Open `http://localhost:8080/marketplace/cart`.

1. Open Saved baskets.
2. Load the breakfast basket. The seed key is `breakfast-shipping`: one muesli and one honey.
3. Postcode `10115`.
4. Press Optimize Cart.

Expected, from the passing test `testCheapestUnitsAreNotTheCheapestDeliveredCart`:

- Status `OPTIMAL_VERIFIED`
- Both lines from Alpine Roast
- Products EUR 52.00
- Shipping EUR 0.00
- Total EUR 52.00
- The two cheapest unit prices would cost EUR 55.00 after shipping

Do not press pay unless you intend to create an order.

## 4. Smart Cart — quantity must split

Load the bulk oats basket. The seed key is `oats-must-split`: SKU `OATS-5KG`, quantity 6. Postcode `10115`. Optimize.

Expected, from `testQuantityMustSplitWhenNoVendorCanFulfillAlone`:

- Status `OPTIMAL_VERIFIED`
- Alpine Roast 2, Harbor Bean 4, Northwind 0
- Products EUR 50.00
- Shipping EUR 8.00
- Total EUR 58.00

No vendor in the seed has 6 units, so a split is required.

## 5. Smart Cart — not enough stock

Load the limited oats basket. The seed key is `oats-no-stock`: quantity 30. Optimize.

Expected, from `testNoStockScenarioIsInfeasible`:

- Status infeasible
- No allocation
- The explanation says there is not enough eligible stock

## 6. Postcode rule

On the oats basket, set the postcode to `80331` and optimize again.

Northwind’s primary rule only allows prefixes 10, 20, and 75. The test `testIneligiblePostcodeRemovesThatVendorBeforeAllocation` still returns the 2+4 split and EUR 58.00, with Northwind quantity 0.

Harbor also has a second rule, “Munich weight delivery”, for prefix 80. That rule is not the nationwide oats total above. Mention it as an extra rule, not as a change to the EUR 58.00 oats result at 10115.

## 7. Vendor dashboard

Open `http://localhost:8080/marketplace/vendor`.

Sign in as Alpine Roast, then as Harbor Bean.

Each session should list only that vendor’s sub-orders. The controller checks `password_verify` and rejects a vendor whose status is not `active` (`modules/marketplacevendor/controllers/front/dashboard.php`). This screen was not opened during the 7 November test run.

## 8. Admin marketplace

Sign in at `http://localhost:8080/admin-dev/index.php`.

Open Marketplace in the back office.

| Screen | What to show |
| --- | --- |
| Dashboard | GMV, order count, commission, vendor count, offer count, recent orders, and charts fed by those totals |
| Vendors | Change a commission percent or set a vendor to suspended, then sign in as that vendor and show the suspension message |
| Offer import | Upload `modules/marketplacecatalog/data/sample-offers.csv` |
| Orders | Parent reference and vendor sub-orders |
| Collections | Breakfast and Aperitivo available, Christmas and Easter dated |

Finance links in the sidebar (Commissions, Settlements, Analytics) scroll to sections of the dashboard. Settings opens the Marketplace Core module configuration. They are not separate applications.

## 9. Checkout, only if you need it

Checkout needs a registered customer.

1. Register at `http://localhost:8080/marketplace/account`.
2. Put a small product in Smart Cart and optimize.
3. Continue to `http://localhost:8080/marketplace/pay`.
4. Card keeps the last four digits. Bank transfer is stored as awaiting payment.

Say clearly that this does not charge a real card and does not use Mangopay. Order email may fail in Docker. The receipt should still exist.

## What not to claim

- Do not say a test passed unless it is in `reports/PRESTASHOP_DEMO_TEST_RESULTS.md`.
- Do not demonstrate a vendor signup form, a CSV export, a nightly import, an invoice PDF, a refund, or a payout.
- Do not describe the admin charts as a business-intelligence product. They plot the marketplace rows already stored.
- Hummingbird’s sample clothes can still exist in the database. Cart and category URLs are redirected into the marketplace. The sample catalog was not deleted.
