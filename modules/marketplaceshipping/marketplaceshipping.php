<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;

class Marketplaceshipping extends Module
{
    public function __construct()
    {
        $this->name = 'marketplaceshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Marketplace Demo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['marketplacecore'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        Autoload::register();
        parent::__construct();
        $this->displayName = $this->l('Marketplace Shipping');
        $this->description = $this->l('Per-vendor base shipping, weight surcharge, postcode eligibility and free-shipping thresholds.');
    }

    public function install(): bool
    {
        return parent::install();
    }
}
