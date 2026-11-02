<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/Autoload.php';

use Marketplace\Core\Application\MarketplaceApp;
use Marketplace\Core\Autoload;
use Marketplace\Core\Database\Connection;
use Marketplace\Core\Install\DemoSeeder;
use Marketplace\Core\Install\SchemaInstaller;
use Marketplace\Core\Install\TabInstaller;
use Marketplace\Core\Domain\Money;
use Marketplace\Core\Repository\CartRepository;
use Marketplace\Core\Repository\VendorRepository;

class Marketplacecore extends Module
{
    public function __construct()
    {
        $this->name = 'marketplacecore';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Marketplace Demo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        Autoload::register();
        parent::__construct();
        $this->displayName = $this->l('Marketplace Core');
        $this->description = $this->l('Schema, demo seed data and shared services for the multi-vendor marketplace.');
    }

    public function install(): bool
    {
        try {
            if (!parent::install() || !$this->registerHook(['displayHeader', 'displayNav1', 'displayTop', 'displayHome', 'moduleRoutes', 'actionFrontControllerInitBefore'])) {
                return false;
            }
            (new SchemaInstaller())->install();
            (new DemoSeeder())->seed();
            TabInstaller::ensureParent();

            return true;
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();
            fwrite(STDERR, $exception->getMessage() . PHP_EOL . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL);

            return false;
        }
    }

    public function uninstall(): bool
    {
        try {
            TabInstaller::remove('AdminMarketplace');
            (new SchemaInstaller())->uninstall();
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();

            return false;
        }

        return parent::uninstall();
    }

    public function getContent(): string
    {
        $output = '';
        if (Tools::isSubmit('submitMarketplaceReseed')) {
            try {
                (new DemoSeeder())->seed();
                $output .= $this->displayConfirmation($this->l('Demo catalog, vendors, shipping and collections were refreshed.'));
            } catch (Throwable $exception) {
                $output .= $this->displayError($exception->getMessage());
            }
        }

        $links = [
            $this->l('Cart optimizer') => $this->context->link->getModuleLink('marketplaceoptimizer', 'cart'),
            $this->l('Catalog') => $this->context->link->getModuleLink('marketplacecatalog', 'catalog'),
            $this->l('Collections') => $this->context->link->getModuleLink('marketplacecollections', 'list'),
            $this->l('Vendor dashboard') => $this->context->link->getModuleLink('marketplacevendor', 'dashboard'),
        ];
        $html = '<div class="panel"><h3>Marketplace demo</h3><ul>';
        foreach ($links as $label => $url) {
            $html .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars($label, ENT_QUOTES) . '</a></li>';
        }
        $html .= '</ul><form method="post"><button class="btn btn-default" name="submitMarketplaceReseed" type="submit">Refresh demo catalog</button></form></div>';

        return $output . $html;
    }

    public function hookActionFrontControllerInitBefore(): void
    {
        $this->leaveNativeStorefront();
    }

    public function hookDisplayHeader(): string
    {
        if (!$this->isRegisteredInHook('actionFrontControllerInitBefore')) {
            $this->registerHook('actionFrontControllerInitBefore');
        }
        $this->leaveNativeStorefront();
        $jsVersion = (string) (@filemtime($this->local_path . 'views/js/marketplace.js') ?: time());
        if (!$this->isRegisteredInHook('displayAfterBodyOpeningTag')) {
            $this->registerHook('displayAfterBodyOpeningTag');
        }
        if (!$this->isRegisteredInHook('displayFooterBefore')) {
            $this->registerHook('displayFooterBefore');
        }
        if (!$this->isRegisteredInHook('displayBeforeBodyClosingTag')) {
            $this->registerHook('displayBeforeBodyClosingTag');
        }

        $controller = (string) ($this->context->controller->php_self ?? '');
        if ($controller === 'module-marketplacevendor-dashboard') {
            return $this->dashboardAssets();
        }

        $cssVersion = (string) (@filemtime($this->local_path . 'views/css/marketplace.css') ?: time());
        $themeVersion = (string) (@filemtime($this->local_path . 'views/css/theme.css') ?: time());

        return '<link rel="stylesheet" href="' . $this->_path . 'views/css/theme.css?v=' . $themeVersion . '">'
            . '<script src="https://cdn.tailwindcss.com"></script>'
            . '<script>tailwind.config={corePlugins:{preflight:false},theme:{extend:{fontFamily:{sans:["Manrope","Inter","ui-sans-serif","system-ui"]},colors:{ink:"#1c1917",navy:"#1c2434",accent:"#312e81",canvas:"#f6f3ee",line:"#e7e3dc",muted:"#78716c",emerald:"#0f766e"},borderRadius:{card:"16px",btn:"12px",promo:"24px"},boxShadow:{card:"0 18px 40px -28px rgba(28,25,23,.45)"}}}}</script>'
            . '<script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>'
            . '<script src="https://cdn.jsdelivr.net/npm/embla-carousel@8.5.1/embla-carousel.umd.js"></script>'
            . '<script src="https://cdn.jsdelivr.net/npm/embla-carousel-autoplay@8.5.1/embla-carousel-autoplay.umd.js"></script>'
            . '<link rel="stylesheet" href="' . $this->_path . 'views/css/marketplace.css?v=' . $cssVersion . '">'
            . '<script src="' . $this->_path . 'views/js/marketplace.js?v=' . $jsVersion . '"></script>'
            . '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">'
            . '<style>'
            . '#header,.header-top,.header-banner,.breadcrumb,#notifications,.ps-imageslider,#ps_imageslider,.ps-customtext,.ps-featuredproducts,.ps-banner,.ps-newproducts,.ps-specials,.ps-emailsubscription,.block-newsletter,.footer__main{display:none!important}'
            . '#footer{background:transparent;margin:0;padding:0;border:0}'
            . 'body{background:#f6f3ee;color:#1c1917}'
            . '.page-content,.page-content>*{width:100%;max-width:none;justify-self:stretch}'
            . '.footer.footer__before{background:transparent;margin:0;padding:0}'
            . '</style>';
    }

    private function leaveNativeStorefront(): void
    {
        if (Tools::getIsset('ajax')) {
            return;
        }
        $page = (string) ($this->context->controller->php_self ?? '');
        if (in_array($page, ['cart', 'order', 'order-opc'], true)) {
            Tools::redirect($this->context->link->getModuleLink('marketplaceoptimizer', 'cart'));
        }
        if (in_array($page, ['category', 'search', 'new-products', 'prices-drop', 'best-sales', 'manufacturer', 'supplier'], true)) {
            Tools::redirect($this->context->link->getModuleLink('marketplacecatalog', 'catalog'));
        }
    }

    public function hookDisplayAfterBodyOpeningTag(): string
    {
        if ((string) ($this->context->controller->php_self ?? '') === 'module-marketplacevendor-dashboard') {
            return '';
        }

        return $this->renderNav();
    }

    private function dashboardAssets(): string
    {
        $jsVersion = (string) (@filemtime($this->local_path . 'views/js/marketplace.js') ?: time());
        $themeVersion = (string) (@filemtime($this->local_path . 'views/css/theme.css') ?: time());
        $chartsVersion = (string) (@filemtime($this->local_path . 'views/js/charts.js') ?: time());

        return '<link rel="stylesheet" href="' . $this->_path . 'views/css/theme.css?v=' . $themeVersion . '">'
            . '<script src="https://cdn.tailwindcss.com"></script>'
            . '<script>tailwind.config={theme:{extend:{fontFamily:{sans:["Manrope","Inter","ui-sans-serif"]},colors:{ink:"#1c1917",navy:"#1c2434",accent:"#312e81",canvas:"#f6f3ee",line:"#e7e3dc",muted:"#78716c",emerald:"#0f766e"},borderRadius:{card:"16px",btn:"12px",promo:"24px"},boxShadow:{card:"0 18px 40px -28px rgba(28,25,23,.45)"}}}}</script>'
            . '<script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>'
            . '<script src="https://unpkg.com/react@18.3.1/umd/react.production.min.js"></script>'
            . '<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js"></script>'
            . '<script src="https://unpkg.com/prop-types@15.8.1/prop-types.min.js"></script>'
            . '<script src="https://unpkg.com/recharts@2.15.0/umd/Recharts.js"></script>'
            . '<script src="' . $this->_path . 'views/js/charts.js?v=' . $chartsVersion . '"></script>'
            . '<script src="' . $this->_path . 'views/js/marketplace.js?v=' . $jsVersion . '"></script>'
            . '<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">'
            . '<style>'
            . 'body{background:#f6f3ee;color:#1c1917;font-family:Manrope,Inter,sans-serif}'
            . '#header,#footer,.header-top,.header-banner,.breadcrumb,#notifications,.footer__main{display:none!important}'
            . '#wrapper,#main,#content,#content-wrapper,.page-content,.page--content-only{max-width:none!important;width:100%!important;margin:0!important;padding:0!important;background:#f6f3ee}'
            . '</style>';
    }

    public function hookDisplayTop(): string
    {
        if ((string) ($this->context->controller->php_self ?? '') === 'module-marketplacevendor-dashboard') {
            return '';
        }

        return $this->renderNav();
    }

    public function hookDisplayNav1(): string
    {
        if ((string) ($this->context->controller->php_self ?? '') === 'module-marketplacevendor-dashboard') {
            return '';
        }

        return $this->renderNav();
    }

    public function hookDisplayFooterBefore(): string
    {
        if ((string) ($this->context->controller->php_self ?? '') === 'module-marketplacevendor-dashboard') {
            return '';
        }
        $this->assignLinks();

        return $this->display(__FILE__, 'views/templates/hook/footer.tpl');
    }

    public function hookDisplayBeforeBodyClosingTag(): string
    {
        if ((string) ($this->context->controller->php_self ?? '') === 'module-marketplacevendor-dashboard') {
            return '';
        }

        return '<style>'
            . '#header,.header-top,.header-banner,.footer__main,.breadcrumb,#notifications,'
            . '.ps-imageslider,#ps_imageslider,.ps-customtext,.ps-featuredproducts,.featured-products,'
            . '.ps-banner,.ps-newproducts,.ps-specials,.ps-emailsubscription,.block-newsletter{display:none!important}'
            . '.product__container:has(.mp-pdp) .product__name,'
            . '.product__container:has(.mp-pdp) .product__manufacturer,'
            . '.product__container:has(.mp-pdp) .product__description-short,'
            . '.product__container:has(.mp-pdp) .product__prices,'
            . '.product__container:has(.mp-pdp) .product__bottom,'
            . 'body:has(.mp-pdp) .product__bottom,'
            . 'body:has(.mp-pdp) .module-products,'
            . 'body:has(.mp-pdp) .product-comments-wrapper,'
            . 'body:has(.mp-pdp) .social-sharing{display:none!important}'
            . '</style>';
    }

    public function hookDisplayHome(): string
    {
        $this->assignLinks();
        $app = new MarketplaceApp();
        $products = $app->catalog((string) ($this->context->cookie->mp_postcode ?: '10115'));
        $offers = 0;
        foreach ($products as $product) {
            $offers += count($product['offers']);
        }
        $vendors = (new VendorRepository(new Connection()))->all();
        $ruleRows = (new VendorRepository(new Connection()))->rulesGrouped();
        foreach ($vendors as &$vendor) {
            $extra = [];
            foreach ($ruleRows[(int) $vendor['id_marketplace_vendor']] ?? [] as $rule) {
                if ((int) ($vendor['shipping_rule_id'] ?? 0) === (int) $rule['id_marketplace_shipping_rule']) {
                    continue;
                }
                $extra[] = $rule['name'] . ' · ' . Money::euros((int) $rule['base_cents'])
                    . ' + ' . Money::euros((int) $rule['surcharge_cents_per_kg']) . '/kg for postcodes '
                    . $rule['postcode_prefixes'];
            }
            $vendor['extra_shipping'] = implode(' ', $extra);
        }
        unset($vendor);
        $this->context->smarty->assign([
            'mp_vendor_count' => count($vendors),
            'mp_vendors' => $vendors,
            'mp_offer_count' => $offers,
            'mp_product_count' => count($products),
            'mp_products' => $products,
            'mp_collections' => $app->collections(),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/home.tpl');
    }

    public function hookModuleRoutes(): array
    {
        return [
            'module-marketplaceoptimizer-cart' => $this->route('cart', 'marketplace/cart', 'marketplaceoptimizer'),
            'module-marketplaceorders-receipt' => $this->route('receipt', 'marketplace/order/{reference}', 'marketplaceorders', ['reference' => ['regexp' => '[0-9]+', 'param' => 'reference']]),
            'module-marketplacecatalog-catalog' => $this->route('catalog', 'marketplace/catalog', 'marketplacecatalog'),
            'module-marketplacecollections-list' => $this->route('list', 'marketplace/collections', 'marketplacecollections'),
            'module-marketplacevendor-dashboard' => $this->route('dashboard', 'marketplace/vendor', 'marketplacevendor'),
            'module-marketplacevendor-password' => $this->route('password', 'marketplace/vendor/password', 'marketplacevendor'),
            'module-marketplaceorders-account' => $this->route('account', 'marketplace/account', 'marketplaceorders'),
            'module-marketplaceorders-payment' => $this->route('payment', 'marketplace/pay', 'marketplaceorders'),
        ];
    }

    private static bool $navRendered = false;

    private function renderNav(): string
    {
        if (self::$navRendered) {
            return '';
        }
        self::$navRendered = true;
        $this->assignLinks();

        return $this->display(__FILE__, 'views/templates/hook/nav.tpl');
    }

    private function assignLinks(): void
    {
        $this->context->smarty->assign([
            'mp_home_url' => $this->context->link->getPageLink('index'),
            'mp_account_url' => $this->context->link->getModuleLink('marketplaceorders', 'account'),
            'mp_cart_url' => $this->context->link->getModuleLink('marketplaceoptimizer', 'cart'),
            'mp_catalog_url' => $this->context->link->getModuleLink('marketplacecatalog', 'catalog'),
            'mp_collections_url' => $this->context->link->getModuleLink('marketplacecollections', 'list'),
            'mp_vendor_url' => $this->context->link->getModuleLink('marketplacevendor', 'dashboard'),
            'mp_query' => (string) Tools::getValue('q', ''),
            'mp_cart_count' => $this->cartCount(),
        ]);
    }

    private function cartCount(): int
    {
        $token = (string) ($this->context->cookie->mp_cart ?? '');
        if ($token === '') {
            return 0;
        }
        $row = (new Connection())->one(
            'SELECT `id_marketplace_cart` FROM ' . Connection::table('marketplace_cart')
            . ' WHERE `token` = ' . Connection::quote($token)
        );
        if (!$row) {
            return 0;
        }
        $count = 0;
        foreach ((new CartRepository(new Connection()))->lines((int) $row['id_marketplace_cart']) as $line) {
            $count += (int) $line['quantity'];
        }

        return $count;
    }

    private function route(string $controller, string $rule, string $module, array $keywords = []): array
    {
        return [
            'controller' => $controller,
            'rule' => $rule,
            'keywords' => $keywords,
            'params' => [
                'fc' => 'module',
                'module' => $module,
            ],
        ];
    }
}
