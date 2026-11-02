<?php

declare(strict_types=1);

namespace Marketplace\Orders\Adapter;

use Marketplace\Core\Database\Connection;
use Marketplace\Core\Repository\OrderRepository;

/**
 * Writes one native shop order per vendor shipment without decrementing
 * PrestaShop stock. Marketplace stock is already reserved by confirmLines.
 */
final class PrestaShopOrderGateway
{
    public function __construct(private readonly Connection $db = new Connection())
    {
    }

    /**
     * @param array<string, mixed> $checkout
     * @return list<array{id_order: int, reference: string}>
     */
    public function create(string $reference, array $checkout): array
    {
        $parent = (new OrderRepository($this->db))->findParent($reference);
        if ($parent === null) {
            throw new \RuntimeException('The marketplace order was not found.');
        }
        $customerId = (int) ($checkout['id_customer'] ?? 0);
        $customer = new \Customer($customerId);
        if (!$customer->id) {
            throw new \RuntimeException('The customer account was not found.');
        }

        $context = \Context::getContext();
        $addressId = $this->addressId($customer, (string) $parent['postcode']);
        $previousCart = (int) $context->cookie->id_cart;
        $cart = new \Cart();
        $cart->id_customer = $customerId;
        $cart->id_address_delivery = $addressId;
        $cart->id_address_invoice = $addressId;
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) ($customer->id_lang ?: $context->language->id);
        $cart->id_shop = (int) $context->shop->id;
        $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->secure_key = (string) $customer->secure_key;
        if (!$cart->add()) {
            throw new \RuntimeException('The shop cart could not be created.');
        }
        if ($previousCart > 0) {
            $context->cookie->id_cart = $previousCart;
            $context->cookie->write();
        }

        $card = (string) ($checkout['payment_method'] ?? '') === 'card';
        $stateId = (int) ($card ? \Configuration::get('PS_OS_PAYMENT') : \Configuration::get('PS_OS_BANKWIRE'));
        if ($stateId < 1) {
            $stateId = $card ? 2 : 10;
        }
        $employeeId = (int) $this->db->value(
            'SELECT `id_employee` FROM `' . _DB_PREFIX_ . 'employee` ORDER BY `id_employee` ASC'
        );
        if ($employeeId < 1) {
            $employeeId = 1;
        }
        $now = date('Y-m-d H:i:s');
        $created = [];
        $orders = new OrderRepository($this->db);
        foreach ($parent['vendors'] as $vendor) {
            $shopReference = (string) $vendor['reference'];
            $productCents = (int) $vendor['product_cents'];
            $shippingCents = (int) $vendor['shipping_cents'];
            $grandCents = (int) $vendor['grand_cents'];
            $this->db->exec(
                'INSERT INTO `' . _DB_PREFIX_ . 'orders` ('
                . '`reference`, `id_shop_group`, `id_shop`, `id_carrier`, `id_lang`, `id_customer`, `id_cart`, `id_currency`, '
                . '`id_address_delivery`, `id_address_invoice`, `current_state`, `secure_key`, `payment`, `conversion_rate`, `module`, '
                . '`total_paid`, `total_paid_tax_incl`, `total_paid_tax_excl`, `total_paid_real`, `total_products`, `total_products_wt`, '
                . '`total_shipping`, `total_shipping_tax_incl`, `total_shipping_tax_excl`, `invoice_date`, `delivery_date`, `valid`, '
                . '`date_add`, `date_upd`, `note`'
                . ') VALUES ('
                . Connection::quote($shopReference) . ', ' . (int) $context->shop->id_shop_group . ', ' . (int) $context->shop->id . ', '
                . (int) (\Configuration::get('PS_CARRIER_DEFAULT') ?: 1) . ', ' . (int) $cart->id_lang . ', ' . $customerId . ', '
                . (int) $cart->id . ', ' . (int) $cart->id_currency . ', ' . $addressId . ', ' . $addressId . ', ' . $stateId . ', '
                . Connection::quote((string) $customer->secure_key) . ', ' . Connection::quote($card ? 'Card' : 'Bank transfer') . ', 1, '
                . Connection::quote('marketplaceorders') . ', '
                . $this->money($grandCents) . ', ' . $this->money($grandCents) . ', ' . $this->money($grandCents) . ', '
                . $this->money($card ? $grandCents : 0) . ', ' . $this->money($productCents) . ', ' . $this->money($productCents) . ', '
                . $this->money($shippingCents) . ', ' . $this->money($shippingCents) . ', ' . $this->money($shippingCents) . ', '
                . Connection::quote($now) . ', ' . Connection::quote($now) . ', ' . ($card ? 1 : 0) . ', '
                . Connection::quote($now) . ', ' . Connection::quote($now) . ', '
                . Connection::quote('Marketplace order #' . $reference . ' / ' . $shopReference)
                . ')'
            );
            $orderId = $this->db->lastId();
            if ($orderId < 1) {
                throw new \RuntimeException('The shop order was not saved.');
            }
            $weight = 0.0;
            foreach ($vendor['lines'] as $line) {
                $grams = (int) $this->db->value(
                    'SELECT `weight_grams` FROM ' . Connection::table('marketplace_vendor_offer')
                    . ' WHERE `id_marketplace_vendor_offer` = ' . (int) $line['id_marketplace_vendor_offer']
                );
                $weight += $grams / 1000;
                $this->db->exec(
                    'INSERT INTO `' . _DB_PREFIX_ . 'order_detail` ('
                    . '`id_order`, `id_shop`, `product_id`, `product_attribute_id`, `product_name`, `product_quantity`, '
                    . '`product_price`, `product_weight`, `tax_name`, `tax_rate`, `unit_price_tax_incl`, `unit_price_tax_excl`, '
                    . '`total_price_tax_incl`, `total_price_tax_excl`, `original_product_price`'
                    . ') VALUES ('
                    . $orderId . ', ' . (int) $context->shop->id . ', ' . (int) $line['id_product'] . ', 0, '
                    . Connection::quote((string) $line['product_name']) . ', ' . (int) $line['quantity'] . ', '
                    . $this->money((int) $line['unit_price_cents']) . ', ' . number_format($grams / 1000, 6, '.', '') . ', '
                    . Connection::quote('') . ', 0, '
                    . $this->money((int) $line['unit_price_cents']) . ', ' . $this->money((int) $line['unit_price_cents']) . ', '
                    . $this->money((int) $line['line_total_cents']) . ', ' . $this->money((int) $line['line_total_cents']) . ', '
                    . $this->money((int) $line['unit_price_cents'])
                    . ')'
                );
            }
            $this->db->exec(
                'INSERT INTO `' . _DB_PREFIX_ . 'order_carrier` (`id_order`, `id_carrier`, `weight`, `shipping_cost_tax_excl`, `shipping_cost_tax_incl`, `date_add`) VALUES ('
                . $orderId . ', ' . (int) (\Configuration::get('PS_CARRIER_DEFAULT') ?: 1) . ', '
                . number_format($weight, 6, '.', '') . ', ' . $this->money($shippingCents) . ', ' . $this->money($shippingCents) . ', '
                . Connection::quote($now) . ')'
            );
            $this->db->exec(
                'INSERT INTO `' . _DB_PREFIX_ . 'order_history` (`id_employee`, `id_order`, `id_order_state`, `date_add`) VALUES ('
                . $employeeId . ', ' . $orderId . ', ' . $stateId . ', ' . Connection::quote($now) . ')'
            );
            if ($card) {
                $this->db->exec(
                    'INSERT INTO `' . _DB_PREFIX_ . 'order_payment` ('
                    . '`order_reference`, `id_currency`, `amount`, `payment_method`, `conversion_rate`, `transaction_id`, '
                    . '`card_number`, `card_brand`, `card_expiration`, `card_holder`, `date_add`'
                    . ') VALUES ('
                    . Connection::quote($shopReference) . ', ' . (int) $cart->id_currency . ', ' . $this->money($grandCents) . ', '
                    . Connection::quote('Card') . ', 1, ' . Connection::quote($reference) . ', '
                    . Connection::quote((string) ($checkout['card_last4'] ?? '')) . ', '
                    . Connection::quote((string) ($checkout['card_brand'] ?? 'Card')) . ', '
                    . Connection::quote((string) ($checkout['card_expiry'] ?? '')) . ', '
                    . Connection::quote((string) ($checkout['card_holder'] ?? '')) . ', '
                    . Connection::quote($now) . ')'
                );
            }
            $orders->attachShopOrder((int) $vendor['id_marketplace_vendor_order'], $orderId, $shopReference);
            $created[] = ['id_order' => $orderId, 'reference' => $shopReference];
        }

        return $created;
    }

    private function addressId(\Customer $customer, string $postcode): int
    {
        $existing = (int) $this->db->value(
            'SELECT `id_address` FROM `' . _DB_PREFIX_ . 'address` WHERE `id_customer` = ' . (int) $customer->id
            . ' AND `alias` = ' . Connection::quote('Marketplace') . ' AND `deleted` = 0'
        );
        $address = $existing > 0 ? new \Address($existing) : new \Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = (int) \Country::getByIso('DE') ?: (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $address->alias = 'Marketplace';
        $address->firstname = (string) $customer->firstname;
        $address->lastname = (string) $customer->lastname;
        $address->address1 = 'Marketplace delivery';
        $address->postcode = $postcode;
        $address->city = 'Local delivery';
        $address->phone = '0000000000';
        $saved = $existing > 0 ? $address->update() : $address->add();
        if (!$saved || !(int) $address->id) {
            throw new \RuntimeException('A delivery address could not be saved.');
        }

        return (int) $address->id;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 6, '.', '');
    }
}
