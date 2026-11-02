<?php

declare(strict_types=1);

namespace Marketplace\Core;

/**
 * PSR-4 loader used inside PrestaShop, where the repository Composer autoload is not installed.
 */
final class Autoload
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        $modules = dirname(__DIR__, 2);

        spl_autoload_register(static function (string $class) use ($modules): void {
            $prefixes = [
                'Marketplace\\Core\\' => $modules . '/marketplacecore/src/',
                'Marketplace\\Vendor\\' => $modules . '/marketplacevendor/src/',
                'Marketplace\\Catalog\\' => $modules . '/marketplacecatalog/src/',
                'Marketplace\\Shipping\\' => $modules . '/marketplaceshipping/src/',
                'Marketplace\\Optimizer\\' => $modules . '/marketplaceoptimizer/src/',
                'Marketplace\\Orders\\' => $modules . '/marketplaceorders/src/',
                'Marketplace\\Commission\\' => $modules . '/marketplacecommission/src/',
                'Marketplace\\Analytics\\' => $modules . '/marketplaceanalytics/src/',
                'Marketplace\\Collections\\' => $modules . '/marketplacecollections/src/',
            ];

            foreach ($prefixes as $prefix => $directory) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                $file = $directory . $relative;
                if (is_file($file)) {
                    require_once $file;
                }

                return;
            }
        });
    }
}
