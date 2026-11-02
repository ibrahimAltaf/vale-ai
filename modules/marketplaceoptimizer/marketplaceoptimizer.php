<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;

class Marketplaceoptimizer extends Module
{
    public function __construct()
    {
        $this->name = 'marketplaceoptimizer';
        $this->tab = 'checkout';
        $this->version = '1.0.0';
        $this->author = 'Marketplace Demo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['marketplacecore', 'marketplaceshipping'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        Autoload::register();
        parent::__construct();
        $this->displayName = $this->l('Marketplace Cart Optimizer');
        $this->description = $this->l('Allocates a cart across vendors by lowest total checkout cost, including shipping.');
    }

    public function install(): bool
    {
        return parent::install();
    }
}
