# Module inventory

All marketplace modules are proprietary. None of them are third-party marketplace products. PrestaShop core files are not modified.

| Module | Responsibility |
| --- | --- |
| `marketplacecore` | Autoload, schema installer, money, demo catalog, storefront shell, repositories |
| `marketplacevendor` | Vendor registration, login, password reset, vendor dashboard |
| `marketplacecatalog` | Offer CSV parser, product matcher, catalog pages, admin import |
| `marketplaceshipping` | Shipping quote and rule selector |
| `marketplaceoptimizer` | Smart-cart search and demo scenarios |
| `marketplaceorders` | Storefront checkout, payment page, native PrestaShop order gateway, mail templates |
| `marketplacecommission` | Commission calculator used by the storefront and the platform ledger |
| `marketplaceanalytics` | Admin dashboard totals |
| `marketplacecollections` | Merchandising collections |
| `marketplaceplatform` | `/api/v1`, payments, KYC, payouts, invoices, cron maintenance, platform schema |

`bin/install-modules.php` enables the modules and installs `marketplaceplatform` last. `composer.json` autoloads `Marketplace\Platform\` from `modules/marketplaceplatform/src`. The PrestaShop autoload map in `marketplacecore` does the same for shop requests.

## Services that must stay stable

These classes are used by the existing demo and the original PHPUnit suite. The platform calls them. It does not reimplement them.

- `Marketplace\Core\Domain\Money`
- `Marketplace\Optimizer\Service\CartOptimizer`
- `Marketplace\Shipping\Service\ShippingCalculator`
- `Marketplace\Shipping\Service\ShippingRuleSelector`
- `Marketplace\Commission\Service\CommissionCalculator`
- `Marketplace\Catalog\Service\CsvOfferParser`
- `Marketplace\Catalog\Service\ProductMatcher`
- `Marketplace\Core\Seed\DemoCatalog`

`ShippingRuleSelector` returns the first rule when none match the postcode. The platform treats that as a candidate and then calls `ShippingCalculator::postcodeAllowed`. An ineligible offer is skipped. The primary demo rules stay at EUR 0 per kg. Harbor's extra Munich rule, prefix `80` and 150 cents per kg, is added by the installer upgrade, not by changing the primary rule.
