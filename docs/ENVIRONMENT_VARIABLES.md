# Environment variables

Do not commit real values. The shop's PrestaShop database constants (`_DB_*`) are separate from these variables. The API reads the Mangopay and admin variables from the process environment.

| Variable | Required | Purpose |
| --- | --- | --- |
| `MARKETPLACE_ADMIN_EMAIL` | For API admin login | Administrator email |
| `MARKETPLACE_ADMIN_PASSWORD_HASH` | For API admin login | `password_hash` output. Never the raw password |
| `MARKETPLACE_MANGOPAY_CLIENT_ID` | For live sandbox | Mangopay client id |
| `MARKETPLACE_MANGOPAY_API_KEY` | For live sandbox | Mangopay API key. Never log it |
| `MARKETPLACE_MANGOPAY_BASE_URL` | No | Defaults to `https://api.sandbox.mangopay.com` |
| `MARKETPLACE_MANGOPAY_PLATFORM_WALLET` | For settlement | Platform EUR wallet id. Missing wallet returns `503 mangopay_not_configured` |
| `MARKETPLACE_MANGOPAY_BANK_ACCOUNT` | For payouts | Bank account id used when an administrator processes a payout |
| `MARKETPLACE_DB_DSN` | For cron | Example: `mysql:host=127.0.0.1;dbname=prestashop;charset=utf8mb4` |
| `MARKETPLACE_DB_USER` | For cron | Database user |
| `MARKETPLACE_DB_PASSWORD` | For cron | Database password |
| `MARKETPLACE_DB_PREFIX` | No | Defaults to `ps_` |
| `MARKETPLACE_INVOICE_DIR` | No | PDF directory. The shop default is `var/marketplace-invoices` under the PrestaShop root |

If the client id or API key is empty, `HttpGateway::fromEnvironment()` returns `UnavailableGateway`. Payments then fail closed. They do not pretend to succeed.

Example process environment for a local cron, with placeholders:

```text
MARKETPLACE_DB_DSN=mysql:host=127.0.0.1;dbname=prestashop;charset=utf8mb4
MARKETPLACE_DB_USER=prestashop
MARKETPLACE_DB_PASSWORD=replace-me
MARKETPLACE_DB_PREFIX=ps_
MARKETPLACE_INVOICE_DIR=/var/www/html/var/marketplace-invoices
MARKETPLACE_ADMIN_EMAIL=admin@example.com
MARKETPLACE_ADMIN_PASSWORD_HASH=
MARKETPLACE_MANGOPAY_CLIENT_ID=
MARKETPLACE_MANGOPAY_API_KEY=
MARKETPLACE_MANGOPAY_PLATFORM_WALLET=
MARKETPLACE_MANGOPAY_BANK_ACCOUNT=
```

The local Docker database password is the compose default and is not a production secret. Do not reuse it on a public host.
