<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;
use Marketplace\Core\Install\SchemaInstaller;

class Marketplaceplatform extends Module
{
    public function __construct()
    {
        $this->name = 'marketplaceplatform';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Marketplace Demo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->dependencies = ['marketplacecore'];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        Autoload::register();
        parent::__construct();
        $this->displayName = $this->l('Marketplace Platform');
        $this->description = $this->l('Versioned marketplace API, payments, invoices and scheduled imports.');
    }

    public function install(): bool
    {
        if (!parent::install() || !$this->registerHook('moduleRoutes')) {
            return false;
        }
        try {
            (new SchemaInstaller())->upgrade();
        } catch (\Throwable) {
            return false;
        }
        $this->exemptApiFromWebserviceRewrite();

        return true;
    }

    /**
     * PrestaShop's generated .htaccess sends every /api URL to the webservice
     * dispatcher. This exception is placed before the managed block, which
     * PrestaShop preserves when it regenerates the file.
     */
    public function exemptApiFromWebserviceRewrite(): void
    {
        $file = _PS_ROOT_DIR_ . '/.htaccess';
        if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
            return;
        }
        $contents = (string) file_get_contents($file);
        if (str_contains($contents, 'marketplace platform api v1')) {
            return;
        }
        $rule = "<IfModule mod_rewrite.c>\nRewriteEngine On\n# marketplace platform api v1\nRewriteRule ^api/v1(?:/.*)?$ index.php [QSA,END]\n</IfModule>\n\n";
        file_put_contents($file, $rule . $contents);
    }

    public function hookModuleRoutes(): array
    {
        return [
            'module-marketplaceplatform-entry' => [
                'controller' => 'entry',
                'rule' => 'api/v1{path}',
                'keywords' => [
                    'path' => ['regexp' => '/?.*', 'param' => 'path'],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => 'marketplaceplatform',
                    'controller' => 'entry',
                ],
            ],
        ];
    }
}
