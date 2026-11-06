# CSV import and cron

## File format

`CsvOfferParser` requires this header:

```text
vendor,sku,ean,brand,title,description,price,discount,stock,weight,image_url
```

Price is a decimal euro amount. Stock is an integer. Weight is kilograms and is converted with `Money::kilogramsToGrams`. The vendor code in each row must match the authenticated vendor. A bad header or a bad stock value throws before rows are applied. Files over 2 MB are rejected.

## Create, update, retry

The first import of a SKU creates the offer, stock, and a master-product match. A later import of the same vendor and SKU updates price and stock and reports `rows_updated`. An EAN or SKU that matches more than one master product fails that row with `ambiguous_match` and does not merge the products. A failed file can be submitted again. The unique `(vendor, sku)` key makes the retry an update.

`GET /api/v1/vendors/me/imports` lists jobs. `GET /api/v1/vendors/me/exports/offers` and `GET /api/v1/vendors/me/exports/orders` return CSV for that vendor only.

## Schedules

`POST /api/v1/vendors/me/import-schedules` stores the CSV and an interval from 5 to 10080 minutes. Cron does not run a schedule again until the interval has elapsed. `MarketplaceFlowTest` runs maintenance twice and expects the second run to import nothing.

## Cron command

```text
php /home/USER/public_html/bin/marketplace-cron.php
```

cPanel can call that every five minutes. The process needs `MARKETPLACE_DB_DSN`, `MARKETPLACE_DB_USER`, and `MARKETPLACE_DB_PASSWORD`. Optional `MARKETPLACE_DB_PREFIX` defaults to `ps_`. Optional `MARKETPLACE_INVOICE_DIR` sets the PDF directory. The script prints one JSON object from `Platform::runMaintenance`: expired reservations released, and import results.

Docker Compose mounts the script at `/usr/local/bin/marketplace-cron.php`. A container created before that mount was added does not see the file until it is recreated. Recreate with `PS_ERASE_DB=0` so the demo database is kept. This repository change was not deployed to the live host.

Maintenance also runs from `POST /api/v1/admin/maintenance`.
