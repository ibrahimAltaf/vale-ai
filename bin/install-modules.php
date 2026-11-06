<?php

declare(strict_types=1);

/**
 * Installs the marketplace modules after PrestaShop itself is installed.
 * Usage, from the PrestaShop container:
 *   php /usr/local/bin/install-marketplace.php
 */

require '/var/www/html/config/config.inc.php';
require_once '/var/www/html/modules/marketplacecore/src/Autoload.php';

if (!isset($GLOBALS['kernel']) || !$GLOBALS['kernel'] instanceof AdminKernel) {
    $environment = defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ ? 'dev' : 'prod';
    $GLOBALS['kernel'] = new AdminKernel($environment, (bool) _PS_MODE_DEV_);
    $GLOBALS['kernel']->boot();
}

Marketplace\Core\Autoload::register();

$modules = [
    'marketplacecore',
    'marketplacevendor',
    'marketplacecatalog',
    'marketplaceshipping',
    'marketplacecommission',
    'marketplaceoptimizer',
    'marketplaceorders',
    'marketplaceanalytics',
    'marketplacecollections',
    'marketplaceplatform',
];

foreach ($modules as $name) {
    $module = Module::getInstanceByName($name);
    if (!$module instanceof Module) {
        fwrite(STDERR, "Module not found: {$name}\n");
        exit(1);
    }

    if (Module::isInstalled($name)) {
        echo "Already installed: {$name}\n";
        continue;
    }

    if (!$module->install()) {
        if (Module::isInstalled($name)) {
            echo "Already installed: {$name}\n";
            continue;
        }
        fwrite(STDERR, "Install failed: {$name}.\n");
        exit(1);
    }

    echo "Installed {$name}\n";
}

echo "Marketplace demo is ready.\n";
