<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;

class MarketplacecollectionsListModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
        Autoload::register();
    }

    public function initContent(): void
    {
        parent::initContent();
        $this->context->smarty->assign([
            'mp_collections' => (new MarketplaceApp())->collections(),
            'mp_cart_url' => $this->context->link->getModuleLink('marketplaceoptimizer', 'cart'),
        ]);
        $this->setTemplate('module:marketplacecollections/views/templates/front/list.tpl');
    }
}
