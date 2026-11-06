# cPanel deployment

Use this when the shop is a normal PrestaShop document root on cPanel, not the Docker demo and not the existing live Vale host.

## Files

Upload the `modules/marketplace*` directories and `bin/marketplace-cron.php`. Install the modules from the back office, with Marketplace Platform last, or run the project installer if CLI PHP can boot PrestaShop. The platform install runs the additive schema upgrade.

PHP must be 8.2 or newer and must include `pdo_mysql` and `curl`. `pdo_sqlite` is only required to run the platform test suite.

## Cron

In cPanel Cron Jobs, every five minutes:

```text
php /home/USER/public_html/bin/marketplace-cron.php
```

Set the variables in the cron command or in the application environment. cPanel often needs them inline:

```text
MARKETPLACE_DB_DSN='mysql:host=localhost;dbname=USER_prestashop;charset=utf8mb4' MARKETPLACE_DB_USER='USER_prestashop' MARKETPLACE_DB_PASSWORD='replace-me' MARKETPLACE_DB_PREFIX='ps_' php /home/USER/public_html/bin/marketplace-cron.php
```

The command prints one JSON line and exits 0. Exit 1 means `MARKETPLACE_DB_DSN` is missing. It releases reservations whose `expires_at` is in the past and runs import schedules whose interval has elapsed.

## Webservice conflict

PrestaShop owns the `/api` path for its webservice. Marketplace Platform's install writes this rule above the generated block, which PrestaShop preserves:

```apache
RewriteRule ^api/v1(?:/.*)?$ index.php [QSA,END]
```

Without that rule, `/api/v1/health` returns the webservice's `401` instead of the marketplace JSON. Confirm the rule is still present after you regenerate friendly URLs.

## Webhook URL

After HTTPS works, the Mangopay sandbox webhook URL is:

```text
https://YOUR-DOMAIN/api/v1/mangopay/webhooks
```

The route is registered by the `moduleRoutes` hook as `api/v1{path}`. Pretty URLs must be enabled so PrestaShop dispatches that rule. If the shop is in a subdirectory, prefix that path.

## Permissions

`var/marketplace-invoices` must be writable by the PHP user and should not be web-accessible. The application creates it mode `0700` and writes PDFs mode `0600`.

## Limits

CSV imports reject bodies over 2 MB. Invoice PDFs reject files over 5 MB. Raise PHP `post_max_size` and `upload_max_filesize` above 8 MB if vendors upload through a form that wraps the API. The JSON API reads `php://input`, so those ini limits still apply.
