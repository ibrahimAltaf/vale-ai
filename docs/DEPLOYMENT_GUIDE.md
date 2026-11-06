# Deployment guide

This guide is for a new shop or a local Docker shop. Do not apply it to the live host `191.215.35.197` or the database behind `vale.191-215-35-197.sslip.io`. That stack stays as it was deployed.

## Order of work

1. Install PrestaShop 9.2 with `PS_ERASE_DB=0` if a database already has demo data.
2. Mount or copy `modules/marketplace*` and `bin/marketplace-cron.php`.
3. Run `php bin/install-modules.php` from a context that can boot PrestaShop, or install `marketplaceplatform` from the back office. Install depends on `marketplacecore` and calls `SchemaInstaller::upgrade()`.
4. Confirm `GET /api/v1/health` returns JSON and `live_provider_verified: false`.
5. Set the environment variables in [ENVIRONMENT_VARIABLES.md](ENVIRONMENT_VARIABLES.md).
6. Add the cron entry in [CPANEL_DEPLOYMENT.md](CPANEL_DEPLOYMENT.md) or the Docker equivalent.
7. Create the API administrator with `MARKETPLACE_ADMIN_EMAIL` and `MARKETPLACE_ADMIN_PASSWORD_HASH`.
8. Leave Mangopay empty until sandbox keys exist. Payments fail closed.

## Upgrade

`SchemaInstaller::upgrade()` is safe to run more than once. It creates missing tables and adds missing columns. It does not truncate demo vendors, products, or orders `#1001`–`#1004`.

## What not to do

- Do not set `PS_ERASE_DB=1` on a shop that already has the demo.
- Do not store PAN or CVV.
- Do not point a production Mangopay webhook at this API until HTTPS is in place and a sandbox rehearsal has been recorded.
- Do not recreate the running local containers if the only goal is to read the current demo. A recreate is required for new compose mounts, and it must keep `PS_ERASE_DB=0`.

## Checks after deploy

- Existing storefront breakfast scenario still shows grand total 5200 cents when quantities stay 1 and 1.
- `php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --do-not-cache-result` on a checkout machine with the SQLite extension.
- `GET /api/v1/health` on the shop after the module is installed.
- One cron run prints JSON and a second immediate run does not repeat a schedule that just ran.
