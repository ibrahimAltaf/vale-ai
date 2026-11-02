<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;

class AdminMarketplaceImportController extends ModuleAdminController
{
    private ?array $report = null;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        Autoload::register();
    }

    public function postProcess(): void
    {
        if (!Tools::isSubmit('submitMarketplaceImport')) {
            return;
        }
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->errors[] = 'Choose a CSV file.';

            return;
        }
        $contents = (string) file_get_contents((string) $file['tmp_name']);
        try {
            $this->report = (new MarketplaceApp())->importCsv($contents, (string) $file['name']);
        } catch (Throwable $exception) {
            $this->errors[] = $exception->getMessage();
        }
    }

    public function initContent(): void
    {
        $app = new MarketplaceApp();
        $this->context->smarty->assign([
            'report' => $this->report,
            'errors' => $this->errors,
            'jobs' => $app->importJobs(),
            'sample' => _MODULE_DIR_ . 'marketplacecatalog/data/sample-offers.csv',
        ]);
        $this->content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'marketplacecatalog/views/templates/admin/import.tpl');
        parent::initContent();
    }
}
