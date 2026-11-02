<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminMarketplaceController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent(): void
    {
        $dashboard = (int) Tab::getIdFromClassName('AdminMarketplaceDashboard');
        if ($dashboard > 0) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminMarketplaceDashboard'));
        }

        $this->content = '<div class="alert alert-info">Install the Marketplace Analytics module to open the admin dashboard.</div>';
        parent::initContent();
    }
}
