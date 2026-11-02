<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;

class AdminMarketplaceOrdersController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        Autoload::register();
    }

    public function initContent(): void
    {
        $dashboard = (new MarketplaceApp())->adminDashboard();
        $this->context->smarty->assign(['mp_orders' => $dashboard['orders']]);
        $this->content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'marketplaceorders/views/templates/admin/orders.tpl');
        parent::initContent();
    }
}
