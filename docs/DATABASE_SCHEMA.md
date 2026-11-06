# Database schema

The prefix in a PrestaShop shop is normally `ps_`. SQL files use the token `PREFIX_`. MySQL statements are in `modules/marketplaceplatform/sql/mysql.sql` and `modules/marketplacecore/sql/install.sql`. SQLite tests use `modules/marketplaceplatform/sql/sqlite.sql`, which also contains the core tables the platform writes. SQLite has no foreign keys. MySQL uses InnoDB and `utf8mb4_unicode_ci`.

Installation and upgrade call `Schema::apply` or `SchemaInstaller::upgrade`. Both ignore "duplicate" and "already exists" errors. They do not delete rows.

## Core marketplace tables

Defined by `marketplacecore` and extended by the platform:

| Table | Role | Important constraints |
| --- | --- | --- |
| `marketplace_vendor` | Seller account, status, commission percent, password hash | Unique code, slug, email |
| `marketplace_shipping_rule` | Base cents, free-shipping threshold, cents per kg, postcode prefixes | Vendor foreign key |
| `marketplace_vendor_offer` | Vendor price, SKU, EAN, weight, product id | Unique `(vendor, vendor_sku)`. Index `(id_product, active, price_cents)` |
| `marketplace_vendor_stock` | On-hand and reserved quantity | Unique offer id |
| `marketplace_product_match` | EAN/SKU match decision | Vendor foreign key |
| `marketplace_order` | Parent order totals and payment status | Unique reference. Nullable `id_marketplace_shopper` added by upgrade |
| `marketplace_vendor_order` | One shipment per vendor | `fulfillment_status` added by upgrade, default `pending` |
| `marketplace_vendor_order_line` | Allocated offer, quantity, unit price | Offer and product ids |
| `marketplace_stock_reservation` | Active, released, or consumed hold | Offer and vendor-order ids, `expires_at`. Index `mp_reservation_expiry` on `(status, expires_at)` |
| `marketplace_commission` | Positive sale rows and negative refund rows | Gross, percent, fee, net, all in cents |
| `marketplace_import_job` | One CSV execution | Vendor, counts, status |
| `marketplace_collection` | Merchandising collections | Existing storefront feature |
| `marketplace_cart` / `marketplace_cart_line` | Storefront cart | Existing storefront feature |
| `marketplace_settlement` | Earlier settlement rows | Left in place. Platform payouts use `marketplace_payout` |
| `marketplace_password_reset` | Storefront vendor reset tokens | Existing feature |

## Platform tables

| Table | Role | Important constraints |
| --- | --- | --- |
| `marketplace_master_product` | Canonical EAN/SKU record used by imports | Unique EAN, index on SKU |
| `marketplace_shopper` | API customer and Mangopay payer id | Unique email |
| `marketplace_payment` | Pay-in, settlement status, card alias | Unique idempotency key. Unique `(provider, provider_id)`. Foreign key to the parent order |
| `marketplace_mangopay_user` | Vendor legal user and KYC status | One row per vendor |
| `marketplace_mangopay_wallet` | Vendor EUR wallet | Unique `(vendor, currency)` |
| `marketplace_refund` | Refund share, including the commission cents reversed | Indexes on order and payment |
| `marketplace_payout` | Requested or processed payout | Unique idempotency key |
| `marketplace_webhook_event` | Provider notification | Unique `(event_type, resource_id)` |
| `marketplace_reconciliation_line` | Payment, refund, transfer, and transfer-failure amounts | Append-only ledger |
| `marketplace_import_schedule` | CSV body and interval for cron | Interval stored in minutes. `last_run` is a unix time |
| `marketplace_invoice` | Customer invoice `VIN-` or commission invoice `CIN-` | Unique number. Status `pending`, `issued`, or `uploaded` |
| `marketplace_invoice_file` | Stored PDF name, SHA-256, size | One file per invoice |
| `marketplace_audit_log` | Actor, action, subject, detail | No card data |
| `marketplace_api_credential` | Bearer token hash and expiry | Unique token hash |
| `marketplace_api_permission` | Role to permission | Composite primary key |
| `marketplace_idempotency` | Stored financial response | Unique `(actor_key, idempotency_key)` |
| `marketplace_api_hit` | Rate-limit timestamps | Index `(token_hash, created_at)` |
| `marketplace_order_event` | Order status history | Foreign key to the parent order |

## Integrity rules enforced in services

- An offer is unique per vendor and SKU. A repeated import updates that offer.
- Reservation uses `UPDATE ... SET reserved_quantity = reserved_quantity + :qty WHERE offer = :id AND (quantity - reserved_quantity) >= :qty`. The statement must change one row.
- Stock cannot be set below the reserved quantity.
- A second webhook with the same event type and resource id does not create a second payment commission.
- Financial and reservation writes run inside a transaction. MySQL checkout takes `SELECT ... FOR UPDATE` on the stock row. SQLite checkout uses `BEGIN IMMEDIATE`. Nested calls share that transaction through a depth counter.
- MySQL `UNIQUE (provider, provider_id)` still allows multiple NULL provider ids. Succeeded payments store a provider id before the unique key matters for replay.

## Capacity

The schema is ordinary InnoDB indexed by vendor, offer, product, and order. It is sized for the agreed catalog of about 30 vendors and 120,000 offers: the hot lookup is the `(id_product, active, price_cents)` offer index used while building optimizer options. It has not been load-tested at 120,000 rows in this run. The optimizer bound remains 200,000 combinations, and a larger search is reported as a fallback rather than a proven optimum.

See [DATABASE_ERD.md](DATABASE_ERD.md).
