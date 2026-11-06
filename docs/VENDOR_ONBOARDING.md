# Vendor onboarding

## Account

`POST /api/v1/auth/register/vendor` creates a vendor with status `pending`, commission `10.00`, and a code of 1 to 8 letters or digits. If `code` is omitted, one is generated from the name. Duplicate email or code returns `409 conflict`.

The vendor cannot log in until an administrator calls `POST /api/v1/admin/vendors/{id}/approve`. `POST /api/v1/admin/vendors/{id}/suspend` sets `suspended` and login fails again. `POST /api/v1/admin/vendors/{id}/commission` changes the percent through `Money::basisPoints`. The value is a decimal string such as `10.00` or `12.50`.

`PUT /api/v1/vendors/me` updates `name`, `description`, `contact_name`, and `phone` only.

## Catalog and shipping

The vendor creates at least one shipping rule before offers can be quoted. Rules store base cents, an optional free-shipping threshold, surcharge cents per kilogram, and postcode prefixes. An empty prefix list means every postcode.

Offers require SKU, title, price cents, stock, and weight. EAN, when present, is 8 to 14 digits. The same vendor and SKU updates the existing offer. A new product id is assigned when the caller does not send one and the SKU is new. Ambiguous EAN or SKU matches are rejected and are not merged.

## Mangopay

`POST /api/v1/vendors/me/mangopay/identity` with `legal_type` `natural` or `business` creates the provider user and the EUR wallet. Natural users send first name, last name, ISO country, ISO nationality, and `YYYY-MM-DD` birthday.

`POST /api/v1/vendors/me/mangopay/kyc` submits KYC for that user. Payouts stay blocked until `kyc_status` is `VALIDATED`. The memory gateway used in tests marks a regular user validated. The HTTP gateway records the submission and does not invent a validation decision.

Checkout of an order that includes a vendor without a wallet fails at payment with `vendor_onboarding_required`. The reservation is not converted into a paid order.

## Storefront vendors

The existing demo vendors Alpine, Harbor, and Northwind remain active shop vendors. Their passwords and the storefront login screens are unchanged. API registration is a separate account table path and does not rewrite those rows.
