# PrestaShop 9 multi-vendor marketplace demo

Proprietary technical demonstration of a B2B/B2C multi-vendor marketplace. All marketplace behaviour lives in custom modules. PrestaShop core files are not modified, and no third-party marketplace module is used.

This is a 24-hour working build. It is not a finished product.

Live demo: https://vale.191-215-35-197.sslip.io/ — back office at `/admin-dev`, with the demo accounts below. `docker-compose.vale.yml` is the compose file used on that host. It does not publish port 80 or 443; TLS stays on the proxy in front of the shop.

The business rules (matching, shipping, commission, cart allocation, stock reservation, order splitting) are plain PHP services with constructor injection. PrestaShop controllers are thin adapters over those services. Money is calculated in integer cents.

## What you can test

- Three vendors, each with a commission rate, profile and shipping rule.
- Five master products. **Premium Coffee 1kg** (EAN `1234567890123`, SKU `COFFEE-1KG`) is offered by all three vendors at €12.00 / €11.20 / €12.50 with stock 5 / 2 / 20.
- A product page and catalog that keep **cheapest product price** separate from **cheapest delivered cost**.
- EAN/SKU matching with `EXACT_MATCH`, `NO_MATCH` and `AMBIGUOUS_MATCH`. Ambiguous rows are rejected and are not merged.
- CSV import that updates an offer by vendor + SKU, or vendor + EAN, and records an import job.
- **Optimize Cart**, which prices product cost plus shipping. When the search space is fully enumerated the status is `OPTIMAL_VERIFIED`.
- A breakfast scenario where the cheapest unit prices are **not** the cheapest checkout total, because one vendor crosses a free-shipping threshold.
- A bulk-oats scenario that **must split** quantity 6 (no vendor has 6 units) into 2 + 4.
- Order confirmation that reserves stock inside a database transaction, then writes parent order `#1001` and vendor orders `#1001-A` / `#1001-B`.
- Vendor dashboards that show only that vendor’s orders, GMV, commission, net and low-stock offers.
- An admin dashboard for GMV, orders, commission, vendors and offers.
- Collections (Breakfast, Aperitivo, Christmas, Easter) whose live/scheduled/ended status is derived from the date window.

## Architecture

```
modules/
  marketplacecore/          schema, seed, repositories, composition root
  marketplacevendor/        vendor profiles and vendor dashboard
  marketplacecatalog/       master products, offers, matching, CSV import
  marketplaceshipping/      shipping calculator
  marketplaceoptimizer/     cart allocation and the storefront demo
  marketplaceorders/        reservations, parent orders, vendor sub-orders
  marketplacecommission/    gross, commission, net
  marketplaceanalytics/     admin dashboard
  marketplacecollections/   dated collections
```

`Marketplace\Core\Application\MarketplaceApp` is the composition root used by controllers. Domain services do not call PrestaShop. Repositories are the only database boundary.

### Tables

Created by `marketplacecore` (prefix follows the shop, shown here as `ps_`):

| Table | Role |
| --- | --- |
| `ps_marketplace_vendor` | Vendor profile, status, commission, demo login |
| `ps_marketplace_shipping_rule` | Base, free-shipping threshold, weight surcharge, postcode prefixes |
| `ps_marketplace_vendor_offer` | Vendor price, discount, SKU, EAN, weight |
| `ps_marketplace_vendor_stock` | On-hand and reserved quantity |
| `ps_marketplace_product_match` | Match audit (`EXACT_MATCH`, `NO_MATCH`, `AMBIGUOUS_MATCH`) |
| `ps_marketplace_order` | Parent marketplace order |
| `ps_marketplace_vendor_order` | Vendor sub-order (`1001-A`) |
| `ps_marketplace_vendor_order_line` | Split lines |
| `ps_marketplace_stock_reservation` | Active, consumed or released holds, with expiry |
| `ps_marketplace_commission` | Gross, commission, net per vendor order |
| `ps_marketplace_settlement` | Open monthly vendor settlement |
| `ps_marketplace_import_job` | CSV import log |
| `ps_marketplace_collection` | Thematic collection and date window |
| `ps_marketplace_collection_product` | Products in a collection |
| `ps_marketplace_cart` / `ps_marketplace_cart_line` | Demo cart, separate from the native PrestaShop cart |

Amounts in marketplace tables are integer cents. Foreign keys link marketplace tables to each other. Links to `ps_product` are application-level, which matches PrestaShop module practice and keeps uninstall from fighting core deletes.

### How the optimizer works

For each cart line it builds every way to split the requested quantity across vendors who can ship to the postcode and have stock. If the cartesian product of those splits is within 200,000 combinations, every combination is priced and the cheapest is returned as `OPTIMAL_VERIFIED`. Cost is:

```
sum(unit price × quantity) + shipping per vendor
```

Shipping for a vendor is:

- ineligible when the postcode does not match that vendor’s prefixes (an empty prefix list means every postcode)
- `base + (grams × surcharge per kg / 1000)` otherwise
- `0` when the vendor’s product subtotal is greater than or equal to the free-shipping threshold

Tie-break order is lower grand total, then fewer vendors, then lower shipping, then a stable vendor/product signature.

Above the combination bound the service uses a deterministic one-unit greedy fill and returns `FALLBACK_NOT_PROVEN_OPTIMAL`. It does not call that result optimal. An impossible cart returns `INFEASIBLE`.

The demo catalog stays inside the verified bound. The breakfast scenario’s verified total is **€52.00** from Alpine Roast (free shipping). Filling each line from the cheapest unit price costs **€55.00** after two shipping charges. Bulk oats quantity 6 is verified as **2 from Alpine Roast and 4 from Harbor Bean, total €58.00**.

Redis, when `REDIS_HOST` is set, caches an optimization response for 30 seconds. The cache key includes postcode, quantities, prices and available stock. If Redis is down, optimization still runs.

## Requirements

- PrestaShop 9.x (image pinned to 9.2 on PHP 8.4; modules declare compatibility `9.0.0`–`9.99.99`)
- PHP 8.2 or newer, with `pdo_mysql`, `mbstring`, `curl`, `zip`, `intl` and `gd` as required by PrestaShop
- MySQL 8 or MariaDB 10.6+
- Redis 7 is optional
- Composer 2, for the test suite only

## Demo credentials

Back office (`/admin-dev` on the Docker stack):

- Email: `demo@marketplace.local`
- Password: `DemoAdmin123!`

Vendor dashboard (`/marketplace/vendor`):

| Vendor | Email | Password | Commission | Shipping |
| --- | --- | --- | --- | --- |
| Alpine Roast (A) | `alpine@demo.local` | `AlpineDemo123!` | 10% | €5, free from €50, all postcodes |
| Harbor Bean (B) | `harbor@demo.local` | `HarborDemo123!` | 12% | €3, free from €80, all postcodes |
| Northwind Pantry (C) | `northwind@demo.local` | `NorthwindDemo123!` | 8% | €7, free from €40, postcodes 10 / 20 / 75 |

Database user on the Docker stack: `prestashop` / `prestashop`.

## Storefront and back office

Friendly URLs, after the modules are installed:

- `http://localhost:8080/marketplace/cart` — add products, load a scenario, **Optimize Cart**, confirm
- `http://localhost:8080/marketplace/catalog` — master products and offer comparison
- `http://localhost:8080/marketplace/collections` — dated collections
- `http://localhost:8080/marketplace/vendor` — vendor login
- `http://localhost:8080/marketplace/order/1001` — receipt after the first confirmation
- Back office → **Marketplace** → Dashboard, Vendors, Offer import, Orders, Collections

Without friendly URLs the same pages are `index.php?fc=module&module=marketplaceoptimizer&controller=cart` (and the matching module/controller pairs).

Suggested click-through:

1. Open the cart page and press **Shipping beats unit price**.
2. Press **Optimize Cart**. Status is `OPTIMAL_VERIFIED`. The note explains why the higher product subtotal is the cheaper checkout.
3. Load **Must split quantity 6**. Optimize again. Alpine Roast receives 2 and Harbor Bean receives 4.
4. Press **Confirm optimized order**. The receipt shows one parent reference and one sub-order per vendor, with commission and net.
5. Sign in as Alpine Roast and as Harbor Bean. Each dashboard lists only its own sub-order.
6. Open the back office dashboard and the vendor screen. Commission can be edited there.

Postcode `80331` makes Northwind ineligible. Postcode `10115` does not.

## CSV import

Sample file: `modules/marketplacecatalog/data/sample-offers.csv`.

Header:

```csv
vendor,sku,ean,brand,title,description,price,discount,stock,weight,image_url
```

`vendor` is the vendor code (`A`, `B` or `C`). `weight` is kilograms. In the back office, open **Marketplace → Offer import**, upload the sample, and the coffee offer for Alpine Roast is updated (price €11.90, stock 8). The tea row has no catalog match, so a new master product is created. An ambiguous EAN is stored as `AMBIGUOUS_MATCH` and does not create or merge an offer.

The planner (`OfferImporter::plan`) is separate from the PrestaShop writer, so a later cron worker can persist a `pending` job and execute the same plan. This demo runs the writer immediately and stores the job as `completed`.

## Run the tests

From this repository, PHP 8.2+ is enough. PrestaShop does not have to be installed.

```bash
php composer.phar install
php vendor/phpunit/phpunit/phpunit
```

On Windows, if `composer` is not on `PATH`:

```powershell
php composer.phar install
php vendor/phpunit/phpunit/phpunit
```

The suite currently passes **45 tests, 176 assertions** on PHP 8.3. Covered cases: EAN/SKU exact, missing and ambiguous matches; fixed shipping; free-shipping threshold including the exact boundary; weight surcharge; postcode eligibility; commission (10% of €100, vendor rates, half-up rounding); quantity splitting; the verified breakfast and oats allocations with exact totals; no-stock; fallback that is not claimed optimal; reservation success, oversell rejection and expiry; parent/sub-order commission split; collection date windows; CSV update, create and ambiguous rejection.

## Install with Docker

Docker Desktop or a Linux Docker engine is required. The image is `prestashop/prestashop:9.2.0-8.4`. If that tag is unavailable, use `prestashop/prestashop:9.2.x` in `docker-compose.yml`.

```bash
docker compose up -d
```

Wait until the shop responds, then install the modules:

```bash
docker compose exec prestashop php /usr/local/bin/install-marketplace.php
```

The first PrestaShop install often takes a few minutes. The official image can then exit with status 1 because its entrypoint removes the installer directory after that directory is already gone. The database is installed. Start the web container again:

```bash
docker compose start prestashop
```

If the homepage is HTTP 500 and the Apache log says it cannot write the `en-US` locale cache, the shop files were created as root during install:

```bash
docker compose exec prestashop chown -R www-data:www-data /var/www/html/var /var/www/html/img /var/www/html/upload /var/www/html/download /var/www/html/config
docker compose exec prestashop chmod -R ug+rwx /var/www/html/var
```

If the module command says the shop is not ready, wait and run it again.

- Shop: http://localhost:8080
- Back office: http://localhost:8080/admin-dev

`marketplacecore` creates the tables and seeds the demo on install. **Modules → Marketplace Core → Configure → Refresh demo catalog** reloads vendors, offers and collections. That refresh resets seeded stock reservations so the scenarios can be repeated. It does not delete marketplace orders.

## Install on Ubuntu (Nginx, PHP-FPM, MySQL)

This path is for a VPS. Commands assume Ubuntu 24.04, PHP 8.3 and MySQL 8.

```bash
sudo apt update
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-mysql php8.3-curl php8.3-gd \
  php8.3-intl php8.3-mbstring php8.3-xml php8.3-zip unzip
sudo mysql -e "CREATE DATABASE prestashop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'prestashop'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT ALL PRIVILEGES ON prestashop.* TO 'prestashop'@'localhost';
FLUSH PRIVILEGES;"
sudo mkdir -p /var/www/prestashop
cd /tmp
wget https://github.com/PrestaShop/PrestaShop/releases/download/9.2.0/prestashop_9.2.0.zip
sudo unzip prestashop_9.2.0.zip -d /var/www/prestashop
sudo chown -R www-data:www-data /var/www/prestashop
```

Copy `docker/nginx-prestashop.conf` to `/etc/nginx/sites-available/prestashop`, set `server_name` and `root`, then:

```bash
sudo ln -s /etc/nginx/sites-available/prestashop /etc/nginx/sites-enabled/prestashop
sudo nginx -t && sudo systemctl reload nginx
```

Complete the browser installer at `http://your-server/`. Use the database above, English, and Germany if you want EUR. Delete the `install` directory when the installer asks.

Copy this repository’s `modules/marketplace*` directories into `/var/www/prestashop/modules/` and install them in this order, from the back office or CLI:

```bash
cd /var/www/prestashop
sudo -u www-data php bin/console prestashop:module install marketplacecore
sudo -u www-data php bin/console prestashop:module install marketplacevendor
sudo -u www-data php bin/console prestashop:module install marketplacecatalog
sudo -u www-data php bin/console prestashop:module install marketplaceshipping
sudo -u www-data php bin/console prestashop:module install marketplacecommission
sudo -u www-data php bin/console prestashop:module install marketplaceoptimizer
sudo -u www-data php bin/console prestashop:module install marketplaceorders
sudo -u www-data php bin/console prestashop:module install marketplaceanalytics
sudo -u www-data php bin/console prestashop:module install marketplacecollections
```

If the console command is not available in that package build, use `php bin/install-modules.php` from this repository after editing its `require` path, or install each module from **Modules → Module Manager**.

Optional Redis:

```bash
sudo apt install -y redis-server php8.3-redis
```

Set `REDIS_HOST=127.0.0.1` in the PHP-FPM pool environment. The demo also speaks RESP itself, so the PHP Redis extension is not required.

## No core modifications

These modules do not edit, override or fork PrestaShop core. They use the module installer, hooks (`displayHeader`, `displayTop`, `displayNav1`, `displayHome`, `displayProductAdditionalInfo`, `moduleRoutes`), module front controllers, ModuleAdminController tabs, the `Product` and `StockAvailable` APIs for master products, and `Db` for marketplace tables.

## Known demo limitations

- Checkout requires a customer account. Card payments are checked in the shop and only the last four digits are stored; bank transfer is recorded as awaiting payment. Each vendor shipment is written to `ps_orders`. Confirmation mail is sent when the shop mailer can deliver it.
- Vendor login is a marketplace password on `ps_marketplace_vendor`, not a separate PrestaShop employee per vendor. Both customers and vendors can reset that password.
- A vendor can have more than one shipping rule. The matcher keeps the nationwide rule everywhere except a longer matching postcode prefix.
- The three primary demo rules stay at €0/kg so the published 10115 totals stay the same. Harbor Bean also has a Munich rule, prefix 80, at €1.50/kg.
- Image URLs are stored. The seeder does not download them into PrestaShop image records.
- `OPTIMAL_VERIFIED` is only returned when the full combination space was priced. Larger carts receive `FALLBACK_NOT_PROVEN_OPTIMAL`.
- Reservations expire after 30 minutes. Confirming an order marks them `consumed` so expiry does not release sold stock. A later fulfillment step would decrement on-hand quantity.
- Refreshing the demo catalog resets reservations on seeded offers.
- Commission is charged on product sales only. Shipping is not commissioned.
- The Docker stack publishes HTTP on port 8080 for local review. Put TLS in front of Nginx on a VPS.
