<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;
use Marketplace\Core\Install\TabInstaller;

class Marketplacecollections extends Module
{
    public function __construct()
    {
        $this->name = 'marketplacecollections';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Marketplace Demo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['marketplacecore'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        Autoload::register();
        parent::__construct();
        $this->displayName = $this->l('Marketplace Collections');
        $this->description = $this->l('Thematic collections whose live status is derived from start and end dates.');
    }

    public function install(): bool
    {
        try {
            if (!parent::install()) {
                return false;
            }
            TabInstaller::add($this->name, 'AdminMarketplaceCollections', 'Collections', TabInstaller::parentId(), 'collections');

            return true;
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();

            return false;
        }
    }

    public function uninstall(): bool
    {
        TabInstaller::remove('AdminMarketplaceCollections');

        return parent::uninstall();
    }
}
