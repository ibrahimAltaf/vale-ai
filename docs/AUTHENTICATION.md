# Authentication

## Roles

| Role | Who it is | How it is created |
| --- | --- | --- |
| `shopper` | API customer | `POST /api/v1/auth/register/customer` |
| `vendor` | Seller | `POST /api/v1/auth/register/vendor`, then an administrator approves the account |
| `admin` | Marketplace operator | Environment variables, not a database row |

Login is `POST /api/v1/auth/login` with `role`, `email`, and `password`. The response `data.token` is 64 hex characters. Send it as `Authorization: Bearer <token>`.

Passwords must be 12 to 128 characters. They are stored with `password_hash`. A missing account still runs `password_verify` against a dummy hash so the failure timing is closer to a real check. The error for a bad email or password is `401 unauthorized` and does not say which field was wrong.

A vendor with status `pending` or `suspended` cannot log in (`403 vendor_not_active`). Every authenticated request also re-reads a vendor token's status. `POST /api/v1/auth/logout` deletes the presented token hash. Suspending a vendor deletes that vendor's API credentials. Public registration cannot set the commission rate or status. New vendors start at `10.00` percent and status `pending`. Unknown JSON fields are rejected with `422 unexpected_field`.

## API administrator

The API administrator is not the PrestaShop back-office user. Configure:

- `MARKETPLACE_ADMIN_EMAIL`
- `MARKETPLACE_ADMIN_PASSWORD_HASH` from `password_hash`

If either value is empty, admin login returns `503 admin_not_configured`. Tests set these in process memory. Do not put the back-office password into the API hash field unless you intend them to match.

## Authorization

Every protected route has a permission string. On first use the process inserts the role-to-permission rows from `RouteMap` with `INSERT IGNORE`. A token whose role is not listed on the route receives `403 forbidden`. A vendor acting on another vendor's offer, sub-order, or invoice receives `403`. A shopper reading another shopper's order or customer invoice receives `404`, so the response does not reveal that the record exists.

Profile updates accept only `name`, `description`, `contact_name`, and `phone`. Email, status, and commission are not mass-assignable.

## Token storage

`marketplace_api_credential` stores `role`, `subject_id`, `token_hash`, and `expires_at`. The raw token is not stored. Lookup hashes the presented token with SHA-256. Expiry is 30 days from issue.

## Rate limit

`marketplace_api_hit` counts calls in the last 60 seconds. The limit is 120. Health is excluded. The key is the token hash for authenticated calls and a hash of the client address for public calls. `runMaintenance` deletes hit rows older than one day and keeps newer rows. There is no API password-reset endpoint. Storefront vendor reset remains a separate PrestaShop flow.

## Storefront login

The Hummingbird shop still uses PrestaShop customers and the existing marketplace vendor front controllers. That session is not an `/api/v1` bearer token. Vendor password reset on the storefront keeps its own CSRF token and reset token.
