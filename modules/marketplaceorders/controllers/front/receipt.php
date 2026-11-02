<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;

class MarketplaceordersReceiptModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
        Autoload::register();
    }

    public function initContent(): void
    {
        parent::initContent();
        $reference = (string) Tools::getValue('reference');
        $order = (new MarketplaceApp())->orderReceipt($reference);
        $mail = (string) $this->context->cookie->mp_mail;
        $shopError = (string) $this->context->cookie->mp_shop_error;
        if ($mail !== '' || $shopError !== '') {
            unset($this->context->cookie->mp_mail, $this->context->cookie->mp_shop_error);
            $this->context->cookie->write();
        }
        $this->context->smarty->assign([
            'mp_order' => $order,
            'mp_mail' => $mail,
            'mp_shop_error' => $shopError,
            'mp_cart_url' => $this->context->link->getModuleLink('marketplaceoptimizer', 'cart'),
            'mp_account_url' => $this->context->link->getModuleLink('marketplaceorders', 'account'),
        ]);
        $this->setTemplate('module:marketplaceorders/views/templates/front/receipt.tpl');
    }
}
