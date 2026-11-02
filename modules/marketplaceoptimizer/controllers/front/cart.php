<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;
use Marketplace\Core\Database\Connection;
use Marketplace\Core\Repository\CartRepository;
use Marketplace\Core\Repository\CatalogRepository;
use Marketplace\Core\Seed\DemoCatalog;

class MarketplaceoptimizerCartModuleFrontController extends ModuleFrontController
{
    private ?array $optimization = null;

    private string $notice = '';

    public function __construct()
    {
        parent::__construct();
        Autoload::register();
    }

    public function setMedia(): void
    {
        parent::setMedia();
        $this->addCSS(_MODULE_DIR_ . 'marketplacecore/views/css/marketplace.css');
    }

    public function postProcess(): void
    {
        $basket = (string) Tools::getValue('basket');
        if ($basket !== '' && !$this->ajax && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            if ($this->applyBasket($basket)) {
                Tools::redirect($this->context->link->getModuleLink($this->module->name, 'cart'));
            }
            $this->errors[] = 'That basket is not available.';

            return;
        }

        $token = (string) Tools::getValue('token');
        $submitted = Tools::isSubmit('submitAdd') || Tools::isSubmit('submitQty') || Tools::isSubmit('submitRemove')
            || Tools::isSubmit('submitPreset') || Tools::isSubmit('submitPostcode') || Tools::isSubmit('submitOptimize')
            || Tools::isSubmit('submitConfirm');
        if ($submitted && $token !== Tools::getToken(false)) {
            $this->errors[] = 'The form token expired. Reload the page and try again.';

            return;
        }

        $cart = $this->cartRepository();
        $cartId = $this->cartId();
        $postcode = $this->readPostcode();
        if (Tools::isSubmit('submitPostcode') || Tools::isSubmit('submitOptimize') || Tools::isSubmit('submitConfirm')) {
            $cart->setPostcode($cartId, $postcode);
            $this->context->cookie->mp_postcode = $postcode;
        }

        if (Tools::isSubmit('submitAdd')) {
            $productId = (new CatalogRepository(new Connection()))->productIdByReference((string) Tools::getValue('sku'));
            $quantity = max(1, min(99, (int) Tools::getValue('quantity', 1)));
            if ($productId < 1) {
                $this->errors[] = 'That product is not in the marketplace catalog.';
            } else {
                $current = 0;
                foreach ($cart->lines($cartId) as $line) {
                    if ($line['id_product'] === $productId) {
                        $current = $line['quantity'];
                    }
                }
                $cart->setQuantity($cartId, $productId, min(99, $current + $quantity));
                $this->notice = 'Product added to the marketplace cart.';
            }
        }

        if (Tools::isSubmit('submitQty')) {
            $cart->setQuantity($cartId, (int) Tools::getValue('id_product'), max(0, min(99, (int) Tools::getValue('quantity'))));
        }

        if (Tools::isSubmit('submitRemove')) {
            $cart->setQuantity($cartId, (int) Tools::getValue('id_product'), 0);
        }

        if (Tools::isSubmit('submitPreset')) {
            $scenario = (string) Tools::getValue('scenario');
            if (!$this->applyBasket($scenario)) {
                $this->errors[] = 'That basket is not available.';
            } else {
                $this->notice = 'Basket ready. Optimize Cart to price delivery for this postcode.';
            }
        }

        if ($this->errors !== []) {
            return;
        }

        if (Tools::isSubmit('submitConfirm') && !$this->ajax) {
            Tools::redirect($this->context->link->getModuleLink('marketplaceorders', 'payment'));
        }

        $action = strtolower((string) Tools::getValue('action'));
        if ((Tools::isSubmit('submitOptimize') && !$this->ajax) || ($this->ajax && $action === 'optimize')) {
            try {
                $this->optimization = (new MarketplaceApp())->optimizeLines($cart->lines($cartId), $postcode);
            } catch (Throwable $exception) {
                $this->errors[] = $exception->getMessage();
            }
        }
    }

    public function displayAjaxOptimize(): void
    {
        if ($this->errors !== []) {
            $this->renderJson(['ok' => false, 'error' => implode(' ', $this->errors)]);
        }
        $this->renderJson([
            'ok' => true,
            'html' => $this->renderResult($this->optimization),
        ]);
    }

    public function initContent(): void
    {
        parent::initContent();
        $cart = $this->cartRepository();
        $cartId = $this->cartId();
        $postcode = $cart->postcode($cartId);
        if ($postcode === '') {
            $postcode = '10115';
            $cart->setPostcode($cartId, $postcode);
        }
        $app = new MarketplaceApp();
        $this->context->smarty->assign([
            'mp_token' => Tools::getToken(false),
            'mp_postcode' => $postcode,
            'mp_cart' => $app->cartView($cart->lines($cartId), $postcode),
            'mp_products' => $app->catalog($postcode),
            'mp_result' => $this->optimization,
            'mp_result_html' => $this->renderResult($this->optimization),
            'mp_notice' => $this->notice,
            'mp_errors' => $this->errors,
            'mp_scenarios' => [
                'breakfast-shipping' => 'Breakfast basket',
                'oats-must-split' => 'Bulk oats',
                'coffee-split' => 'Coffee case',
                'oats-no-stock' => 'Oats, limited stock',
            ],
        ]);
        $this->setTemplate('module:marketplaceoptimizer/views/templates/front/cart.tpl');
    }

    private function applyBasket(string $scenario): bool
    {
        $quantities = DemoCatalog::scenarioQuantities()[$scenario] ?? null;
        if ($quantities === null) {
            return false;
        }
        $cart = $this->cartRepository();
        $cartId = $this->cartId();
        $cart->clear($cartId);
        $catalog = new CatalogRepository(new Connection());
        foreach ($quantities as $sku => $quantity) {
            $productId = $catalog->productIdByReference($sku);
            if ($productId > 0) {
                $cart->setQuantity($cartId, $productId, $quantity);
            }
        }

        return true;
    }

    private function cartId(): int
    {
        $cookie = $this->context->cookie;
        if (empty($cookie->mp_cart)) {
            $cookie->mp_cart = bin2hex(random_bytes(16));
            $cookie->write();
        }

        return $this->cartRepository()->findOrCreate((string) $cookie->mp_cart, '10115');
    }

    private function cartRepository(): CartRepository
    {
        return new CartRepository(new Connection());
    }

    private function readPostcode(): string
    {
        $postcode = strtoupper(preg_replace('/\s+/', '', (string) Tools::getValue('postcode', '10115')) ?? '');
        if ($postcode === '' || strlen($postcode) > 16) {
            return '10115';
        }

        return $postcode;
    }

    private function renderResult(?array $result): string
    {
        if ($result === null) {
            return '';
        }
        $postcode = (string) Tools::getValue('postcode', $this->context->cookie->mp_postcode ?: '10115');
        $this->context->smarty->assign([
            'mp_result' => $result,
            'mp_token' => Tools::getToken(false),
            'token' => Tools::getToken(false),
            'mp_postcode' => $postcode,
        ]);

        return $this->context->smarty->fetch('module:marketplaceoptimizer/views/templates/front/result.tpl');
    }

    private function renderJson(array $payload): void
    {
        $json = json_encode($payload);
        if (method_exists($this, 'ajaxRender')) {
            $this->ajaxRender((string) $json);

            return;
        }
        header('Content-Type: application/json');
        echo $json;
        exit;
    }
}
