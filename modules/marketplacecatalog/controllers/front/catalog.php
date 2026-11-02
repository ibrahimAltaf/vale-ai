<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;

class MarketplacecatalogCatalogModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
        Autoload::register();
    }

    public function initContent(): void
    {
        parent::initContent();
        $postcode = strtoupper(preg_replace('/\s+/', '', (string) Tools::getValue('postcode', $this->context->cookie->mp_postcode ?: '10115')) ?? '');
        if ($postcode === '') {
            $postcode = '10115';
        }
        $this->context->cookie->mp_postcode = $postcode;
        $this->context->smarty->assign([
            'mp_postcode' => $postcode,
            'mp_query' => trim((string) Tools::getValue('q', '')),
            'mp_products' => (new MarketplaceApp())->catalog($postcode),
            'mp_cart_url' => $this->context->link->getModuleLink('marketplaceoptimizer', 'cart'),
        ]);
        $this->setTemplate('module:marketplacecatalog/views/templates/front/catalog.tpl');
    }
}
