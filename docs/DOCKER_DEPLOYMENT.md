# Docker deployment

Local compose file: `docker-compose.yml`. Image: `prestashop/prestashop:9.2.0-8.4`. Shop URL: `http://localhost:8080`. MySQL is not published to the host. Database name `prestashop`, user `prestashop`, password `prestashop`, root password `prestashop`. `PS_ERASE_DB` is `0`.

## Mounts added for this backend

- `./modules/marketplaceplatform` → `/var/www/html/modules/marketplaceplatform`
- `./bin/marketplace-cron.php` → `/usr/local/bin/marketplace-cron.php` (read-only)

A container that was created before those lines were added does not see the files. Recreate the PrestaShop service only with `PS_ERASE_DB=0`. Do not remove the `db_data` volume. Do not run this compose against the live Vale host. `docker-compose.vale.yml` received the same mount lines in the repository and was not deployed.

## Cron inside the container

```text
docker exec -e MARKETPLACE_DB_DSN='mysql:host=db;dbname=prestashop;charset=utf8mb4' -e MARKETPLACE_DB_USER=prestashop -e MARKETPLACE_DB_PASSWORD=prestashop -e MARKETPLACE_DB_PREFIX=ps_ pristashop-prestashop-1 php /usr/local/bin/marketplace-cron.php
```

That command needs the mount. If the binary is absent, the container has not been recreated.

## Webservice rewrite

PrestaShop's `.htaccess` sends every `/api` URL to the webservice dispatcher. Installing Marketplace Platform writes an exception before PrestaShop's managed block:

```apache
RewriteRule ^api/v1(?:/.*)?$ index.php [QSA,END]
```

PrestaShop keeps lines outside that block when it regenerates `.htaccess`. `GET /api/v1/health` on the local shop returned `200` after this rule was installed. The body reported `payment_mode` `unconfigured` and `live_provider_verified` `false`.

## Module install

After the mount is visible:

```text
docker exec pristashop-prestashop-1 php /usr/local/bin/install-marketplace.php
```

The installer enables modules and upgrades the schema. It does not reseed when the core installer is in upgrade mode. Confirm demo orders `#1001`–`#1004` are still present before and after.

## Smarty cache

Template changes on Windows bind mounts can be ignored by Smarty. This backend does not change storefront templates. If a later change does, clear `var/cache/prod` and `var/cache/dev` inside the container and chown them to `www-data`.

## Tests

Run the suite on the host, not inside the shop container. The container image does not have the `vendor/` and `tests/` trees mounted.

```text
php -d extension=pdo_sqlite vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

The WinGet PHP 8.3 build has `php_pdo_sqlite.dll` in `ext` but does not load it from `php.ini`. The `-d` flag loads it for that process only.
