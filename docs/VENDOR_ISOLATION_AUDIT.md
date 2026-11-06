# Vendor isolation audit

Date: 7 November 2026. The marketplace keeps many vendors in one database. A vendor id in a URL or body is not authority. `Platform::assertVendor` allows the owning vendor and an administrator. A shopper is filtered by `id_marketplace_shopper`. A shopper asking for another shopper's order or customer invoice receives 404. A vendor asking for another vendor's offer, sub-order, or invoice receives 403 when the id is addressable, or an empty list when the query is scoped to the authenticated vendor.

## Identity rule

The authenticated subject comes from the bearer token hash. Login stores `role`, `subject_id`, and `token_hash`. Vendor routes use that subject id. Admin routes may pass a vendor id because `assertVendor` treats the admin role as oversight. That does not let a vendor pass another vendor's id.

API shoppers and PrestaShop customers are different tables. Isolation between them is "no shared key", not a joined tenant check. See the identity strategy in `docs/PRODUCTION_READINESS_REPORT.md`.

## Private records and the check

| Record | Check | Test |
| --- | --- | --- |
| Vendor orders | List query is `id_marketplace_vendor` of the token | `SecurityRegressionTest` expects the other vendor's order export and list to be empty |
| Order billing on a shopper order | Shopper id match, otherwise 404 | `MarketplaceFlowTest` shopper mismatch |
| Stock and offers | Writes use the token vendor. `setStock` loads the offer and checks the owner | Flow tests create offers only as the logged-in vendor |
| Invoices and credit-note style commission invoices | `assertInvoiceAccess`: admin, owning vendor, or the shopper on that customer invoice | Flow test: another shopper and another vendor receive 404 |
| CSV import jobs | Insert and list use the token vendor. Each CSV row's vendor code must match | Other vendor's import list is empty in `SecurityRegressionTest` |
| Offer and order CSV exports | Same vendor scope. Formula prefix on text cells | Title `=1+1` exports as `'=1+1` for the owner only |
| Commission statements | Ledger query is the token vendor | Vendor commission read in the payment flow |
| Settlements and payouts | Payout insert uses the token vendor and that vendor's available balance | A vendor cannot call `process`, which is admin-only and fails the anonymous test |
| Shipping rules | Insert uses the token vendor | Used by checkout setup |
| Profile | Update whitelist is name, description, contact name, and phone | Commission and status are `unexpected_field` |
| Refunds | Admin only | Anonymous test returns 401. The payment flow uses the admin token |
| Audit log | `GET /api/v1/admin/audit` is admin only | Anonymous test returns 401 |

## Cross-vendor proof

`SecurityRegressionTest::testVendorCannotAssignCommissionOrReadAnotherVendorsExport` registers two vendors. The second vendor's import list and order export do not contain the first vendor's rows.

`MarketplaceFlowTest` covers a second shopper reading the first shopper's order and invoice, and a second vendor downloading the first vendor's invoice. Both are 404.

The anonymous matrix proves a caller with no token cannot reach vendor or admin routes. It does not, by itself, prove that a valid vendor token is rejected on every id. The id-specific cases above are the ones with fixtures. A route that only has the anonymous test is marked that way in `docs/API_PAYLOAD_VALIDATION_MATRIX.md`.

## Administrator oversight

Approve, suspend, commission, admin order list, reconciliation, audit, and maintenance require role `admin`. Suspension deletes the vendor's tokens, so the next call is 401 rather than a still-valid vendor session. An administrator can still read those vendor's rows through the admin routes. That is the intended oversight path.

## What was not claimed

Every SQL string in the module was not printed into this file. New reads and writes in the payment and stock changes use the order id already loaded for the authenticated actor, or a reservation joined to that order. A future query that accepts a vendor id from the body and does not call `assertVendor` would be a new defect. `quote` takes `vendor_id` because shipping quotes are not private account data. The quote does not return orders, payouts, or customers.
