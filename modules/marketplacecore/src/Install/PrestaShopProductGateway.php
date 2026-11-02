<?php

declare(strict_types=1);

namespace Marketplace\Core\Install;

use Marketplace\Core\Domain\Money;

final class PrestaShopProductGateway
{
    public function upsert(string $sku, string $ean, string $name, string $description, int $priceCents, string $categoryName = 'Grocery'): int
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $idShop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        if ($idShop > 0 && class_exists(\Shop::class)) {
            \Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);
        }

        $home = (int) \Configuration::get('PS_HOME_CATEGORY');
        if ($home < 1) {
            $home = 2;
        }

        $existing = (int) \Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `reference` = \'' . pSQL($sku) . '\''
        );
        $product = $existing > 0 ? new \Product($existing) : new \Product();
        $product->reference = $sku;
        $product->ean13 = strlen($ean) === 13 ? $ean : '';
        $rewrite = \Tools::str2url($name);
        if ($rewrite === '') {
            $rewrite = \Tools::str2url($sku);
        }
        $names = [];
        $links = [];
        $descriptions = [];
        $shorts = [];
        foreach (\Language::getLanguages(false) as $language) {
            $languageId = (int) $language['id_lang'];
            $names[$languageId] = $name;
            $links[$languageId] = $rewrite;
            $descriptions[$languageId] = '<p>' . htmlspecialchars($description, ENT_QUOTES) . '</p>';
            $shorts[$languageId] = $description;
        }
        if ($names === []) {
            $names[$idLang] = $name;
            $links[$idLang] = $rewrite;
            $descriptions[$idLang] = '<p>' . htmlspecialchars($description, ENT_QUOTES) . '</p>';
            $shorts[$idLang] = $description;
        }
        $product->name = $names;
        $product->link_rewrite = $links;
        $product->description = $descriptions;
        $product->description_short = $shorts;
        $product->price = (float) Money::format($priceCents);
        $product->id_tax_rules_group = 0;
        $categoryId = $this->categoryId($categoryName, $home, $idLang);
        $product->id_category_default = $categoryId;
        $product->active = 1;
        $product->visibility = 'both';
        $product->available_for_order = 1;
        $product->show_price = 1;
        $product->minimal_quantity = 1;
        $product->redirect_type = '404';
        $product->condition = 'new';
        $product->indexed = 1;
        if (property_exists($product, 'product_type')) {
            $product->product_type = 'standard';
        }
        if (property_exists($product, 'state')) {
            $product->state = 1;
        }

        $saved = $existing > 0 ? $product->update() : $product->add();
        if (!$saved) {
            throw new \RuntimeException('Could not save master product ' . $sku . '.');
        }

        $product->updateCategories(array_values(array_unique([$home, $categoryId])));
        if (class_exists(\StockAvailable::class)) {
            \StockAvailable::setQuantity((int) $product->id, 0, 100, $idShop);
        }

        return (int) $product->id;
    }

    private function categoryId(string $name, int $home, int $idLang): int
    {
        $name = trim($name);
        if ($name === '' || strcasecmp($name, 'Home') === 0) {
            return $home;
        }
        $existing = (int) \Db::getInstance()->getValue(
            'SELECT c.`id_category` FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category`
             WHERE cl.`name` = \'' . pSQL($name) . '\' AND cl.`id_lang` = ' . (int) $idLang
        );
        if ($existing > 0) {
            return $existing;
        }
        $category = new \Category();
        $category->id_parent = $home;
        $category->active = 1;
        $category->is_root_category = 0;
        $names = [];
        $links = [];
        foreach (\Language::getLanguages(false) as $language) {
            $languageId = (int) $language['id_lang'];
            $names[$languageId] = $name;
            $links[$languageId] = \Tools::str2url($name) ?: 'category';
        }
        if ($names === []) {
            $names[$idLang] = $name;
            $links[$idLang] = \Tools::str2url($name) ?: 'category';
        }
        $category->name = $names;
        $category->link_rewrite = $links;
        if (!$category->add()) {
            return $home;
        }

        return (int) $category->id;
    }
}
