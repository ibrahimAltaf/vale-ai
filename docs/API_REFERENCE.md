# API reference

The marketplace HTTP API is versioned at `/api/v1`. The machine-readable contract is [openapi.yaml](openapi.yaml). Importable requests are in `postman/PrestaShop_Marketplace_API.postman_collection.json`. Both files are generated from `Marketplace\Platform\Api\RouteMap` by `php bin/export-api-contract.php`. `ContractDocumentTest` fails if a route is missing from either file.

## Response envelope

Success:

```json
{"data": {}}
```

Paginated success:

```json
{"data": [], "meta": {"page": 1, "per_page": 20, "total": 0}}
```

Error:

```json
{"error": {"code": "validation_error", "message": "Postcode must be 2 to 12 letters or digits."}}
```

`page` is at least 1. `per_page` is clamped to 1–100. Offer sorting accepts only `id`, `price_cents`, and `sku`, with direction `asc` or `desc`.

## Authentication and limits

Send `Authorization: Bearer` plus the 64-character hex token from `POST /api/v1/auth/login`. Tokens expire after 30 days. Only the SHA-256 hash is stored.

Authenticated and anonymous calls, except `GET /api/v1/health`, are limited to 120 requests per 60 seconds. The shop uses the remote address as the anonymous client key. A limited call returns `429` and `rate_limited`.

`POST /api/v1/checkout`, `POST /api/v1/payments`, `POST /api/v1/admin/refunds`, and `POST /api/v1/vendors/me/payouts` require `Idempotency-Key` (`^[A-Za-z0-9_.:-]{8,80}$`). The same actor, key, and body replay the stored response. The same key with a different body returns `409 idempotency_conflict`. Responses with status 500 are not stored.

## Money and card data

Amounts are integer euro cents. A body that contains `cardnumber`, `card_number`, `cvv`, `cvc`, `pan`, or `securitycode` is rejected with `422 card_data_rejected` before the route runs. Payments accept a Mangopay `card_id` only.

## Routes

| Method | Path | Roles | Permission |
| --- | --- | --- | --- |
| GET | `/api/v1/health` | public | `health.read` |
| POST | `/api/v1/auth/register/vendor` | public | `auth.register` |
| POST | `/api/v1/auth/register/customer` | public | `auth.register` |
| POST | `/api/v1/auth/login` | public | `auth.login` |
| POST | `/api/v1/auth/logout` | shopper, vendor, admin | `auth.logout` |
| GET | `/api/v1/vendors/me` | vendor | `vendor.read` |
| PUT | `/api/v1/vendors/me` | vendor | `vendor.update` |
| POST | `/api/v1/vendors/me/shipping-rules` | vendor | `shipping.write` |
| GET | `/api/v1/catalog/products` | shopper, vendor, admin | `catalog.read` |
| GET | `/api/v1/offers` | shopper, vendor, admin | `offers.read` |
| POST | `/api/v1/vendors/me/offers` | vendor | `offers.write` |
| GET | `/api/v1/inventory` | vendor | `inventory.read` |
| PUT | `/api/v1/inventory/{offerId}` | vendor | `inventory.write` |
| POST | `/api/v1/shipping/quote` | shopper, vendor, admin | `shipping.quote` |
| POST | `/api/v1/cart/optimize` | shopper, vendor, admin | `cart.optimize` |
| POST | `/api/v1/cart/validate` | shopper, vendor, admin | `cart.validate` |
| POST | `/api/v1/checkout` | shopper | `checkout.create` |
| GET | `/api/v1/orders` | shopper | `orders.read` |
| GET | `/api/v1/orders/{id}` | shopper | `orders.read` |
| POST | `/api/v1/orders/{id}/cancel` | shopper | `orders.cancel` |
| GET | `/api/v1/vendors/me/orders` | vendor | `vendor.orders.read` |
| POST | `/api/v1/vendors/me/orders/{id}/fulfill` | vendor | `vendor.orders.fulfill` |
| POST | `/api/v1/payments` | shopper | `payments.create` |
| GET | `/api/v1/payments/{id}` | shopper, admin | `payments.read` |
| POST | `/api/v1/mangopay/webhooks` | public | `webhooks.receive` |
| POST | `/api/v1/vendors/me/mangopay/identity` | vendor | `mangopay.identity` |
| POST | `/api/v1/vendors/me/mangopay/kyc` | vendor | `mangopay.kyc` |
| GET | `/api/v1/vendors/me/mangopay/wallets` | vendor | `mangopay.wallets` |
| POST | `/api/v1/admin/refunds` | admin | `refunds.create` |
| POST | `/api/v1/vendors/me/payouts` | vendor | `payouts.request` |
| GET | `/api/v1/vendors/me/payouts` | vendor | `payouts.read` |
| POST | `/api/v1/admin/payouts/{id}/process` | admin | `payouts.process` |
| GET | `/api/v1/vendors/me/commissions` | vendor | `commissions.read` |
| GET | `/api/v1/admin/reconciliation` | admin | `reconciliation.read` |
| GET | `/api/v1/admin/audit` | admin | `audit.read` |
| POST | `/api/v1/vendors/me/imports` | vendor | `imports.create` |
| GET | `/api/v1/vendors/me/imports` | vendor | `imports.read` |
| POST | `/api/v1/vendors/me/import-schedules` | vendor | `imports.schedule` |
| GET | `/api/v1/vendors/me/exports/offers` | vendor | `exports.offers` |
| GET | `/api/v1/vendors/me/exports/orders` | vendor | `exports.orders` |
| POST | `/api/v1/vendors/me/invoices` | vendor | `invoices.create` |
| POST | `/api/v1/invoices/{id}/issue` | vendor, admin | `invoices.issue` |
| POST | `/api/v1/invoices/{id}/upload` | vendor, admin | `invoices.upload` |
| GET | `/api/v1/invoices` | shopper, vendor, admin | `invoices.read` |
| GET | `/api/v1/invoices/{id}` | shopper, vendor, admin | `invoices.read` |
| GET | `/api/v1/invoices/{id}/download` | shopper, vendor, admin | `invoices.download` |
| POST | `/api/v1/admin/invoices/commission` | admin | `invoices.commission` |
| GET | `/api/v1/admin/vendors` | admin | `admin.vendors.read` |
| POST | `/api/v1/admin/vendors/{id}/approve` | admin | `admin.vendors.approve` |
| POST | `/api/v1/admin/vendors/{id}/suspend` | admin | `admin.vendors.suspend` |
| POST | `/api/v1/admin/vendors/{id}/commission` | admin | `admin.vendors.commission` |
| GET | `/api/v1/admin/orders` | admin | `admin.orders.read` |
| GET | `/api/v1/admin/permissions` | admin | `admin.permissions.read` |
| POST | `/api/v1/admin/maintenance` | admin | `admin.maintenance` |

There are 54 routes. A missing route returns `404 not_found`. `POST /api/v1/auth/logout` revokes the presented bearer token.

## Example: checkout

```http
POST /api/v1/checkout
Authorization: Bearer <token>
Idempotency-Key: checkout-shopper-50
Content-Type: application/json

{"postcode":"10115","lines":[{"product_id":50,"quantity":1}]}
```

`201` returns the parent order, vendor sub-orders, and the optimizer explanation. `409 infeasible` means no eligible allocation. `409 insufficient_stock` means the conditional stock update lost the race. `optimal: false` with status `FALLBACK_NOT_PROVEN_OPTIMAL` means the result is feasible and is not claimed to be the global optimum.

`POST /api/v1/payments` returns `201` when the pay-in succeeds and settlement completes, `202` when the provider or the wallet transfer is still pending, and `402` when the pay-in fails. A failed pay-in does not write commission and releases the reservation. Payment re-checks the hold before calling the provider. A successful webhook that finds no stock leaves the order unpaid and sets settlement status `stock_conflict`.

## Shop boundary

API customers live in `marketplace_shopper`. API checkout writes marketplace orders and reservations. It does not insert `ps_orders` and does not call `PaymentModule::validateOrder`. The storefront path in `marketplaceorders` still creates native PrestaShop orders for a logged-in PrestaShop customer. Those are two identities. Do not copy a shopper id into `id_customer`.
