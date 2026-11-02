<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;
use Marketplace\Core\Install\TabInstaller;

class Marketplaceanalytics extends Module
{
    public function __construct()
    {
        $this->name = 'marketplaceanalytics';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Marketplace Demo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['marketplacecore'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        Autoload::register();
        parent::__construct();
        $this->displayName = $this->l('Marketplace Analytics');
        $this->description = $this->l('Admin dashboard for GMV, orders, commissions and vendors.');
    }

    public function install(): bool
    {
        try {
            if (!parent::install()) {
                return false;
            }
            TabInstaller::add($this->name, 'AdminMarketplaceDashboard', 'Dashboard', TabInstaller::parentId(), 'assessment');

            return true;
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();

            return false;
        }
    }

    public function uninstall(): bool
    {
        TabInstaller::remove('AdminMarketplaceDashboard');

        return parent::uninstall();
    }
}
