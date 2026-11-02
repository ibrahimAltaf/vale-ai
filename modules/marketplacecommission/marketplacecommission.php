<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;

class Marketplacecommission extends Module
{
    public function __construct()
    {
        $this->name = 'marketplacecommission';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Marketplace Demo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['marketplacecore'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        Autoload::register();
        parent::__construct();
        $this->displayName = $this->l('Marketplace Commission');
        $this->description = $this->l('Calculates vendor gross sales, marketplace commission and vendor net.');
    }

    public function install(): bool
    {
        return parent::install();
    }
}
