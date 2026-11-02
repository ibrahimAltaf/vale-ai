<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;

class AdminMarketplaceDashboardController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        Autoload::register();
    }

    public function initContent(): void
    {
        $data = (new MarketplaceApp())->adminDashboard();
        $this->context->smarty->assign($data);
        $this->content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'marketplaceanalytics/views/templates/admin/dashboard.tpl');
        parent::initContent();
    }
}
