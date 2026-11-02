<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;

class AdminMarketplaceCollectionsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        Autoload::register();
    }

    public function initContent(): void
    {
        $this->context->smarty->assign(['collections' => (new MarketplaceApp())->collections()]);
        $this->content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'marketplacecollections/views/templates/admin/collections.tpl');
        parent::initContent();
    }
}
