<?php

declare(strict_types=1);

namespace Marketplace\Core\Repository;

use Marketplace\Core\Database\Connection;

final class CollectionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function upsert(string $name, string $slug, ?string $startsAt, ?string $endsAt, array $productIds): void
    {
        $now = Connection::quote(date('Y-m-d H:i:s'));
        $existing = $this->db->one(
            'SELECT `id_marketplace_collection` FROM ' . Connection::table('marketplace_collection')
            . ' WHERE `slug` = ' . Connection::quote($slug)
        );
        $start = $startsAt === null ? 'NULL' : Connection::quote($startsAt);
        $end = $endsAt === null ? 'NULL' : Connection::quote($endsAt);
        if ($existing) {
            $id = (int) $existing['id_marketplace_collection'];
            $this->db->exec(
                'UPDATE ' . Connection::table('marketplace_collection')
                . ' SET `name` = ' . Connection::quote($name)
                . ', `active` = 1, `date_start` = ' . $start . ', `date_end` = ' . $end . ', `date_upd` = ' . $now
                . ' WHERE `id_marketplace_collection` = ' . $id
            );
        } else {
            $this->db->exec(
                'INSERT INTO ' . Connection::table('marketplace_collection')
                . ' (`name`, `slug`, `active`, `date_start`, `date_end`, `date_add`, `date_upd`) VALUES ('
                . Connection::quote($name) . ', ' . Connection::quote($slug) . ', 1, ' . $start . ', ' . $end . ', ' . $now . ', ' . $now . ')'
            );
            $id = $this->db->lastId();
        }

        $this->db->exec(
            'DELETE FROM ' . Connection::table('marketplace_collection_product')
            . ' WHERE `id_marketplace_collection` = ' . $id
        );
        $position = 0;
        foreach ($productIds as $productId) {
            $this->db->exec(
                'INSERT INTO ' . Connection::table('marketplace_collection_product')
                . ' (`id_marketplace_collection`, `id_product`, `position`) VALUES ('
                . $id . ', ' . (int) $productId . ', ' . $position++ . ')'
            );
        }
    }

    public function all(): array
    {
        $langId = (int) \Configuration::get('PS_LANG_DEFAULT');
        $shopId = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $collections = $this->db->all(
            'SELECT * FROM ' . Connection::table('marketplace_collection') . ' ORDER BY `name` ASC'
        );
        foreach ($collections as &$collection) {
            $collection['products'] = $this->db->all(
                'SELECT cp.`id_product`, p.`reference`, pl.`name`
                 FROM ' . Connection::table('marketplace_collection_product') . ' cp
                 INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = cp.`id_product`
                 INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $langId . ' AND pl.`id_shop` = ' . $shopId . '
                 WHERE cp.`id_marketplace_collection` = ' . (int) $collection['id_marketplace_collection'] . '
                 ORDER BY cp.`position` ASC'
            );
        }

        return $collections;
    }
}
