<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;
use Marketplace\Core\Install\TabInstaller;

class Marketplaceorders extends Module
{
    public function __construct()
    {
        $this->name = 'marketplaceorders';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Marketplace Demo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['marketplacecore', 'marketplaceoptimizer', 'marketplacecommission'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        Autoload::register();
        parent::__construct();
        $this->displayName = $this->l('Marketplace Orders');
        $this->description = $this->l('Stock reservations, parent marketplace orders and vendor sub-orders.');
    }

    public function install(): bool
    {
        try {
            if (!parent::install()) {
                return false;
            }
            TabInstaller::add($this->name, 'AdminMarketplaceOrders', 'Orders', TabInstaller::parentId(), 'shopping_cart');

            return true;
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();

            return false;
        }
    }

    public function uninstall(): bool
    {
        TabInstaller::remove('AdminMarketplaceOrders');

        return parent::uninstall();
    }
}
