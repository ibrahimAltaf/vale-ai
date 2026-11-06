# API payload validation matrix

Date: 7 November 2026. Source of routes: `RouteMap`. Source of body rules: `Payload`. There are 54 routes. OpenAPI and the Postman collection were regenerated with `php bin/export-api-contract.php` and `ContractDocumentTest` matches that count.

`Payload::check` runs for every matched route. A schema route rejects unknown properties and wrong JSON types. A route without a schema rejects any non-empty object. The webhook route requires `EventType` and `RessourceId` and allows extra provider fields. Integers must be JSON numbers, not numeric strings. `free_threshold_cents` may be null. Cart lines may contain only integer `product_id` and `quantity`.

String length, password length (12–128), money ranges, postcode shape, and enum-like statuses are still checked in the domain methods. `Payload` does not repeat those limits. That gap is S5 in `docs/API_SECURITY_AUDIT.md`.

List routes `products`, `offers`, `inventory`, `orders`, `vendorOrders`, `invoices`, `adminVendors`, and `adminOrders` document `page` and `per_page`. `per_page` is clamped to 1–100. Offers also accept `vendor_id`, `product_id`, `ean`, `sort` (`id`, `price_cents`, `sku`), and `direction` (`asc`, `desc`).

Checkout, pay, refund, and payout require `Idempotency-Key` matching `^[A-Za-z0-9_.:-]{8,80}$`. The same actor, key, and body replay the stored response. A different body returns `409 idempotency_conflict`. Health is not rate limited. Other routes are limited to 120 calls per 60 seconds.

## Coverage key

| Mark | Meaning |
| --- | --- |
| Anonymous | `SecurityRegressionTest::testEveryProtectedRouteRejectsAnonymousCallers` |
| Invalid | A dedicated bad-body or bad-path assertion, or the public empty-body call in that same test |
| Schema file | The path, method, and bearer flag exist in `docs/openapi.yaml` |
| Business | A PHPUnit flow asserts a successful or domain-level response |

Newman executed all 54 generated requests. Its assertions only require a JSON envelope. Forty-nine protected calls returned 401 because `{{token}}` is empty, and the webhook returned 401 `webhook_unverified` from the memory gateway. Newman is not the business-success column. Details are in `docs/PRODUCTION_READINESS_REPORT.md`.

## Routes

| Method and path | Roles | Permission | Body | Tests |
| --- | --- | --- | --- | --- |
| GET `/api/v1/health` | public | `health.read` | none | Anonymous not 401. Shop HTTP 200. Schema file |
| POST `/api/v1/auth/register/vendor` | public | `auth.register` | name, email, password required. code optional. Unknown fields rejected | Invalid commission and status. Business register in the flow tests |
| POST `/api/v1/auth/register/customer` | public | `auth.register` | email, password, first name, last name | Empty body is not 401. Business register in the flow tests |
| POST `/api/v1/auth/login` | public | `auth.login` | role, email, password | Empty body is not 401. Business login in the flow tests |
| POST `/api/v1/auth/logout` | shopper, vendor, admin | `auth.logout` | empty | Anonymous 401. Business revoke then 401 |
| GET `/api/v1/vendors/me` | vendor | `vendor.read` | none | Anonymous 401. Business after login |
| PUT `/api/v1/vendors/me` | vendor | `vendor.update` | name, description, contact name, phone, all optional | Anonymous 401. Unknown commission field rejected |
| POST `/api/v1/vendors/me/shipping-rules` | vendor | `shipping.write` | name, base, surcharge, prefixes required. free threshold nullable | Anonymous 401. Business in checkout setup |
| GET `/api/v1/catalog/products` | shopper, vendor, admin | `catalog.read` | none. page | Anonymous 401. Business list in the flow tests |
| GET `/api/v1/offers` | shopper, vendor, admin | `offers.read` | none. filters above | Anonymous 401. Shop HTTP 401 without a token. Business list |
| POST `/api/v1/vendors/me/offers` | vendor | `offers.write` | sku, title, price, stock required. product, ean, brand, description, discount, weight, image optional | Anonymous 401. Business upsert |
| GET `/api/v1/inventory` | vendor | `inventory.read` | none. page | Anonymous 401. Business list |
| PUT `/api/v1/inventory/{offerId}` | vendor | `inventory.write` | quantity required | Anonymous 401. Bad path 422 on the orders pattern. Business stock update |
| POST `/api/v1/shipping/quote` | shopper, vendor, admin | `shipping.quote` | vendor id, postcode, product cents, weight | Anonymous 401. Business quote |
| POST `/api/v1/cart/optimize` | shopper, vendor, admin | `cart.optimize` | postcode and lines | Anonymous 401. Optimizer tests |
| POST `/api/v1/cart/validate` | shopper, vendor, admin | `cart.validate` | postcode and lines | Anonymous 401. Flow validate |
| POST `/api/v1/checkout` | shopper | `checkout.create` | postcode and lines. Idempotency-Key | Anonymous 401. Business checkout and stock races |
| GET `/api/v1/orders` | shopper | `orders.read` | none. page | Anonymous 401. Shopper sees only own orders |
| GET `/api/v1/orders/{id}` | shopper | `orders.read` | none | Anonymous 401. Other shopper 404 |
| POST `/api/v1/orders/{id}/cancel` | shopper | `orders.cancel` | empty | Anonymous 401. Business cancel of an unpaid order |
| GET `/api/v1/vendors/me/orders` | vendor | `vendor.orders.read` | none. page | Anonymous 401. Other vendor's list is empty |
| POST `/api/v1/vendors/me/orders/{id}/fulfill` | vendor | `vendor.orders.fulfill` | empty | Anonymous 401. Business fulfill after payment |
| POST `/api/v1/payments` | shopper | `payments.create` | order id, card id, return URL. Idempotency-Key | Anonymous 401. Success 201, failure 402, expired hold 409 |
| GET `/api/v1/payments/{id}` | shopper, admin | `payments.read` | none | Anonymous 401. Owning shopper read |
| POST `/api/v1/mangopay/webhooks` | public | `webhooks.receive` | EventType and RessourceId. Extra fields allowed | Empty body is not 401. Unverified resource is 401. Duplicate event test |
| POST `/api/v1/vendors/me/mangopay/identity` | vendor | `mangopay.identity` | legal type, names, country, nationality, birthday | Anonymous 401. Memory gateway identity |
| POST `/api/v1/vendors/me/mangopay/kyc` | vendor | `mangopay.kyc` | empty | Anonymous 401. Memory gateway maps REGULAR to VALIDATED. HTTP submit stays SUBMITTED |
| GET `/api/v1/vendors/me/mangopay/wallets` | vendor | `mangopay.wallets` | none | Anonymous 401. Business wallet list |
| POST `/api/v1/admin/refunds` | admin | `refunds.create` | order id, amount cents. Idempotency-Key | Anonymous 401. Partial refund and balance 6750 |
| POST `/api/v1/vendors/me/payouts` | vendor | `payouts.request` | amount cents. Idempotency-Key | Anonymous 401. KYC required. Business request |
| GET `/api/v1/vendors/me/payouts` | vendor | `payouts.read` | none | Anonymous 401. Business list |
| POST `/api/v1/admin/payouts/{id}/process` | admin | `payouts.process` | empty | Anonymous 401. Business process |
| GET `/api/v1/vendors/me/commissions` | vendor | `commissions.read` | none | Anonymous 401. Ledger read |
| GET `/api/v1/admin/reconciliation` | admin | `reconciliation.read` | none. newest 200 | Anonymous 401. Admin read |
| GET `/api/v1/admin/audit` | admin | `audit.read` | none | Anonymous 401. Admin-only read |
| POST `/api/v1/vendors/me/imports` | vendor | `imports.create` | filename and csv | Anonymous 401. Vendor code must match. Ambiguous EAN fails the row |
| GET `/api/v1/vendors/me/imports` | vendor | `imports.read` | none. newest 100 | Anonymous 401. Other vendor sees an empty list |
| POST `/api/v1/vendors/me/import-schedules` | vendor | `imports.schedule` | filename, csv, interval minutes | Anonymous 401. Domain interval is 5 to 10080 minutes |
| GET `/api/v1/vendors/me/exports/offers` | vendor | `exports.offers` | none | Anonymous 401. Formula prefix and vendor scope |
| GET `/api/v1/vendors/me/exports/orders` | vendor | `exports.orders` | none | Anonymous 401. Vendor scope |
| POST `/api/v1/vendors/me/invoices` | vendor | `invoices.create` | vendor order id | Anonymous 401. Business create |
| POST `/api/v1/invoices/{id}/issue` | vendor, admin | `invoices.issue` | empty | Anonymous 401. Business issue |
| POST `/api/v1/invoices/{id}/upload` | vendor, admin | `invoices.upload` | content_base64. Must be a PDF, at most 5 MB | Anonymous 401. Magic-byte and path-jail checks in the invoice flow |
| GET `/api/v1/invoices` | shopper, vendor, admin | `invoices.read` | none. page | Anonymous 401. Scope by role |
| GET `/api/v1/invoices/{id}` | shopper, vendor, admin | `invoices.read` | none | Anonymous 401. Other shopper and other vendor 404 |
| GET `/api/v1/invoices/{id}/download` | shopper, vendor, admin | `invoices.download` | none | Anonymous 401. Same ownership rule. Body is base64 |
| POST `/api/v1/admin/invoices/commission` | admin | `invoices.commission` | vendor order id | Anonymous 401. Admin create |
| GET `/api/v1/admin/vendors` | admin | `admin.vendors.read` | none. page | Anonymous 401. Admin list |
| POST `/api/v1/admin/vendors/{id}/approve` | admin | `admin.vendors.approve` | empty | Anonymous 401. Business approve |
| POST `/api/v1/admin/vendors/{id}/suspend` | admin | `admin.vendors.suspend` | empty | Anonymous 401. Token deleted |
| POST `/api/v1/admin/vendors/{id}/commission` | admin | `admin.vendors.commission` | commission_percent | Anonymous 401. This is the only route that accepts that field |
| GET `/api/v1/admin/orders` | admin | `admin.orders.read` | none. page | Anonymous 401. Admin oversight |
| GET `/api/v1/admin/permissions` | admin | `admin.permissions.read` | none | Anonymous 401. Admin read |
| POST `/api/v1/admin/maintenance` | admin | `admin.maintenance` | empty | Anonymous 401. Retention test calls `runMaintenance` directly |

## Invalid cases exercised

| Case | Result |
| --- | --- |
| Protected route, no token | 401 `unauthorized` for all 50 protected routes |
| Vendor register with `commission_percent` and `status` | 422 `unexpected_field`. Stored commission stays 10.00 |
| Profile update with those fields | 422 `unexpected_field` |
| `GET /api/v1/orders/not-a-number` | 422 `validation_error` |
| Shop `Content-Type: text/plain` | 415 `unsupported_media_type` |
| Shop body `{` | 400 `invalid_json` |
| Raw card field names | 422 `card_data_rejected` in the payment flow test |

A separate invalid-body test was not written for every write route. Routes that share `Payload` get the same unknown-field and type rules. Routes whose only check is an empty body are covered by the anonymous test for authorization, not by a second authenticated 422 assertion.
