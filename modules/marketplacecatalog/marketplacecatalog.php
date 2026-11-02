<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;
use Marketplace\Core\Install\TabInstaller;

class Marketplacecatalog extends Module
{
    public function __construct()
    {
        $this->name = 'marketplacecatalog';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Marketplace Demo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['marketplacecore'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        Autoload::register();
        parent::__construct();
        $this->displayName = $this->l('Marketplace Catalog');
        $this->description = $this->l('Master products, vendor offers, matching and CSV import.');
    }

    public function install(): bool
    {
        try {
            if (!parent::install() || !$this->registerHook(['displayProductAdditionalInfo'])) {
                return false;
            }
            TabInstaller::add($this->name, 'AdminMarketplaceImport', 'Offer import', TabInstaller::parentId(), 'cloud_upload');

            return true;
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();

            return false;
        }
    }

    public function uninstall(): bool
    {
        TabInstaller::remove('AdminMarketplaceImport');

        return parent::uninstall();
    }

    public function hookDisplayProductAdditionalInfo(array $params): string
    {
        $product = $params['product'] ?? null;
        $id = 0;
        if ((is_array($product) || $product instanceof ArrayAccess) && isset($product['id_product'])) {
            $id = (int) $product['id_product'];
        } elseif (is_object($product) && isset($product->id_product)) {
            $id = (int) $product->id_product;
        } elseif (is_object($product) && method_exists($product, 'getId')) {
            $id = (int) $product->getId();
        }
        if ($id < 1) {
            $id = (int) Tools::getValue('id_product');
        }
        if ($id < 1) {
            return '';
        }

        $postcode = (string) ($this->context->cookie->mp_postcode ?: '10115');
        $name = is_array($product) ? (string) ($product['name'] ?? '') : '';
        $card = (new MarketplaceApp())->productCard($id, $name, '', '', $postcode);
        $this->context->smarty->assign([
            'mp_product' => $card,
            'mp_postcode' => $postcode,
            'mp_token' => Tools::getToken(false),
            'mp_cart_url' => $this->context->link->getModuleLink('marketplaceoptimizer', 'cart'),
            'mp_catalog_url' => $this->context->link->getModuleLink('marketplacecatalog', 'catalog'),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/offers.tpl');
    }

}
