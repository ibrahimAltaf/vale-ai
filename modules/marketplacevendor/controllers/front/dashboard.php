<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;
use Marketplace\Core\Database\Connection;
use Marketplace\Core\Repository\VendorRepository;

class MarketplacevendorDashboardModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
        Autoload::register();
    }

    public function init(): void
    {
        $this->content_only = true;
        parent::init();
    }

    public function postProcess(): void
    {
        if (Tools::isSubmit('submitVendorLogout')) {
            unset($this->context->cookie->mp_vendor, $this->context->cookie->mp_vendor_hash);
            $this->context->cookie->write();

            return;
        }

        if (!Tools::isSubmit('submitVendorLogin')) {
            return;
        }
        if ((string) Tools::getValue('token') !== Tools::getToken(false)) {
            $this->errors[] = 'The form token expired. Reload the page and try again.';

            return;
        }

        $vendor = (new VendorRepository(new Connection()))->findByEmail((string) Tools::getValue('email'));
        if ($vendor === null || !password_verify((string) Tools::getValue('password'), (string) $vendor['password_hash'])) {
            $this->errors[] = 'Unknown vendor email or password.';

            return;
        }
        if ((string) $vendor['status'] !== 'active') {
            $this->errors[] = 'This vendor account is suspended.';

            return;
        }

        $this->context->cookie->mp_vendor = (int) $vendor['id_marketplace_vendor'];
        $this->context->cookie->mp_vendor_hash = $this->signature($vendor);
        $this->context->cookie->write();
    }

    public function initContent(): void
    {
        parent::initContent();
        $dashboard = null;
        $vendorId = (int) $this->context->cookie->mp_vendor;
        if ($vendorId > 0) {
            $vendor = (new VendorRepository(new Connection()))->find($vendorId);
            if ($vendor !== null && hash_equals($this->signature($vendor), (string) $this->context->cookie->mp_vendor_hash)) {
                $dashboard = (new MarketplaceApp())->vendorDashboard($vendorId);
            }
        }

        $this->context->smarty->assign([
            'mp_token' => Tools::getToken(false),
            'mp_dashboard' => $dashboard,
            'mp_errors' => $this->errors,
            'mp_reset_url' => $this->context->link->getModuleLink('marketplacevendor', 'password'),
            'mp_demo_vendors' => [
                ['email' => 'alpine@demo.local', 'password' => 'AlpineDemo123!', 'name' => 'Alpine Roast'],
                ['email' => 'harbor@demo.local', 'password' => 'HarborDemo123!', 'name' => 'Harbor Bean'],
                ['email' => 'northwind@demo.local', 'password' => 'NorthwindDemo123!', 'name' => 'Northwind Pantry'],
            ],
        ]);
        $this->setTemplate('module:marketplacevendor/views/templates/front/dashboard.tpl');
    }

    private function signature(array $vendor): string
    {
        return hash('sha256', $vendor['id_marketplace_vendor'] . '|' . $vendor['password_hash'] . '|' . _COOKIE_KEY_);
    }
}
