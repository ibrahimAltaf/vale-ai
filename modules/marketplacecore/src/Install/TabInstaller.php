<?php

declare(strict_types=1);

namespace Marketplace\Core\Install;

use Marketplace\Core\Autoload;

final class TabInstaller
{
    public static function ensureParent(): void
    {
        Autoload::register();
        if ((int) \Tab::getIdFromClassName('AdminMarketplace') > 0) {
            return;
        }

        self::add('marketplacecore', 'AdminMarketplace', 'Marketplace', self::sellParentId(), 'store');
    }

    public static function add(string $moduleName, string $className, string $label, int $parentId, string $icon = ''): void
    {
        if ((int) \Tab::getIdFromClassName($className) > 0) {
            return;
        }

        $tab = new \Tab();
        $tab->class_name = $className;
        $tab->module = $moduleName;
        $tab->id_parent = $parentId;
        $tab->icon = $icon;
        $tab->active = 1;
        foreach (\Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = $label;
        }
        if (!$tab->add()) {
            throw new \RuntimeException('Could not create admin tab ' . $className . '.');
        }
    }

    public static function remove(string $className): void
    {
        $id = (int) \Tab::getIdFromClassName($className);
        if ($id < 1) {
            return;
        }
        $tab = new \Tab($id);
        $tab->delete();
    }

    public static function parentId(): int
    {
        self::ensureParent();

        return (int) \Tab::getIdFromClassName('AdminMarketplace');
    }

    private static function sellParentId(): int
    {
        $sell = (int) \Tab::getIdFromClassName('SELL');
        if ($sell > 0) {
            return $sell;
        }

        $orders = (int) \Tab::getIdFromClassName('AdminParentOrders');

        return $orders > 0 ? $orders : 0;
    }
}
