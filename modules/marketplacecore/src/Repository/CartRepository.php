<?php

declare(strict_types=1);

namespace Marketplace\Core\Repository;

use Marketplace\Core\Database\Connection;

final class CartRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findOrCreate(string $token, string $postcode): int
    {
        $existing = $this->db->one(
            'SELECT `id_marketplace_cart` FROM ' . Connection::table('marketplace_cart')
            . ' WHERE `token` = ' . Connection::quote($token)
        );
        if ($existing) {
            return (int) $existing['id_marketplace_cart'];
        }

        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_cart') . ' (`token`, `postcode`, `date_upd`) VALUES ('
            . Connection::quote($token) . ', ' . Connection::quote($postcode) . ', ' . Connection::quote(date('Y-m-d H:i:s')) . ')'
        );

        return $this->db->lastId();
    }

    public function postcode(int $cartId): string
    {
        return $this->db->value(
            'SELECT `postcode` FROM ' . Connection::table('marketplace_cart')
            . ' WHERE `id_marketplace_cart` = ' . (int) $cartId
        );
    }

    public function setPostcode(int $cartId, string $postcode): void
    {
        $this->db->exec(
            'UPDATE ' . Connection::table('marketplace_cart')
            . ' SET `postcode` = ' . Connection::quote($postcode)
            . ', `date_upd` = ' . Connection::quote(date('Y-m-d H:i:s'))
            . ' WHERE `id_marketplace_cart` = ' . (int) $cartId
        );
    }

    /**
     * @return list<array{id_product: int, quantity: int, reference: string, name: string}>
     */
    public function lines(int $cartId): array
    {
        $langId = (int) \Configuration::get('PS_LANG_DEFAULT');
        $shopId = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $rows = $this->db->all(
            'SELECT l.`id_product`, l.`quantity`, p.`reference`, pl.`name`
             FROM ' . Connection::table('marketplace_cart_line') . ' l
             INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = l.`id_product`
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $langId . ' AND pl.`id_shop` = ' . $shopId . '
             WHERE l.`id_marketplace_cart` = ' . (int) $cartId . '
             ORDER BY pl.`name` ASC'
        );
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                'id_product' => (int) $row['id_product'],
                'quantity' => (int) $row['quantity'],
                'reference' => (string) $row['reference'],
                'name' => (string) $row['name'],
            ];
        }

        return $lines;
    }

    public function setQuantity(int $cartId, int $productId, int $quantity): void
    {
        if ($quantity < 1) {
            $this->db->exec(
                'DELETE FROM ' . Connection::table('marketplace_cart_line')
                . ' WHERE `id_marketplace_cart` = ' . (int) $cartId
                . ' AND `id_product` = ' . (int) $productId
            );

            return;
        }

        $existing = $this->db->one(
            'SELECT `id_marketplace_cart_line` FROM ' . Connection::table('marketplace_cart_line')
            . ' WHERE `id_marketplace_cart` = ' . (int) $cartId
            . ' AND `id_product` = ' . (int) $productId
        );
        if ($existing) {
            $this->db->exec(
                'UPDATE ' . Connection::table('marketplace_cart_line')
                . ' SET `quantity` = ' . (int) $quantity
                . ' WHERE `id_marketplace_cart_line` = ' . (int) $existing['id_marketplace_cart_line']
            );

            return;
        }

        $this->db->exec(
            'INSERT INTO ' . Connection::table('marketplace_cart_line')
            . ' (`id_marketplace_cart`, `id_product`, `quantity`) VALUES ('
            . (int) $cartId . ', ' . (int) $productId . ', ' . (int) $quantity . ')'
        );
    }

    public function clear(int $cartId): void
    {
        $this->db->exec(
            'DELETE FROM ' . Connection::table('marketplace_cart_line')
            . ' WHERE `id_marketplace_cart` = ' . (int) $cartId
        );
    }
}
