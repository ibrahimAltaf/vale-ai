<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;

class AdminMarketplaceVendorsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        Autoload::register();
    }

    public function postProcess(): void
    {
        if (!Tools::isSubmit('submitVendorUpdate')) {
            return;
        }
        try {
            (new MarketplaceApp())->updateVendor(
                (int) Tools::getValue('id_marketplace_vendor'),
                (string) Tools::getValue('status'),
                (string) Tools::getValue('commission'),
            );
            $this->confirmations[] = 'Vendor updated.';
        } catch (Throwable $exception) {
            $this->errors[] = $exception->getMessage();
        }
    }

    public function initContent(): void
    {
        $this->context->smarty->assign([
            'vendors' => (new MarketplaceApp())->vendorRows(),
            'errors' => $this->errors,
            'confirmations' => $this->confirmations,
        ]);
        $this->content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'marketplacevendor/views/templates/admin/vendors.tpl');
        parent::initContent();
    }
}
