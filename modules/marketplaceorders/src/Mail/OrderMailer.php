<?php

declare(strict_types=1);

namespace Marketplace\Orders\Mail;

use Marketplace\Core\Database\Connection;
use Marketplace\Core\Domain\Money;
use Marketplace\Core\Repository\OrderRepository;

final class OrderMailer
{
    public function sendOrder(string $reference): bool
    {
        $order = (new OrderRepository(new Connection()))->findParent($reference);
        if ($order === null) {
            return false;
        }
        $customerId = (int) ($order['id_customer'] ?? 0);
        $customer = new \Customer($customerId);
        if (!$customer->id || (string) $customer->email === '') {
            return false;
        }

        $shipments = [];
        foreach ($order['vendors'] as $vendor) {
            $lines = [];
            foreach ($vendor['lines'] as $line) {
                $lines[] = (int) $line['quantity'] . ' x ' . (string) $line['product_name'];
            }
            $shipments[] = (string) $vendor['vendor_name'] . ' #' . (string) $vendor['reference']
                . (empty($vendor['shop_reference']) ? '' : ' (shop ' . $vendor['shop_reference'] . ')')
                . ': ' . implode(', ', $lines)
                . ' — ' . Money::euros((int) $vendor['grand_cents']);
            $this->deliver(
                (string) $this->vendorEmail((int) $vendor['id_marketplace_vendor']),
                (string) $vendor['vendor_name'],
                'Shipment ' . $vendor['reference'],
                [
                    '{firstname}' => (string) $vendor['vendor_name'],
                    '{reference}' => (string) $vendor['reference'],
                    '{total}' => Money::euros((int) $vendor['grand_cents']),
                    '{payment}' => (string) ($order['payment_method'] ?? ''),
                    '{status}' => (string) ($order['status'] ?? ''),
                    '{shipments}' => implode("\n", $lines),
                ]
            );
        }

        return $this->deliver(
            (string) $customer->email,
            trim((string) $customer->firstname . ' ' . $customer->lastname),
            'Your Prista Market order ' . $reference,
            [
                '{firstname}' => (string) $customer->firstname,
                '{reference}' => $reference,
                '{total}' => Money::euros((int) $order['grand_cents']),
                '{payment}' => (string) ($order['payment_method'] ?? ''),
                '{status}' => (string) ($order['payment_status'] ?? $order['status'] ?? ''),
                '{shipments}' => implode("\n", $shipments),
            ]
        );
    }

    public function sendReset(string $email, string $name, string $link): bool
    {
        return $this->deliver($email, $name, 'Reset your Prista Market password', [
            '{firstname}' => $name,
            '{reference}' => '',
            '{total}' => '',
            '{payment}' => '',
            '{status}' => '',
            '{shipments}' => $link,
        ], 'password_reset');
    }

    /**
     * @param array<string, string> $vars
     */
    private function deliver(string $email, string $name, string $subject, array $vars, string $template = 'marketplace_order'): bool
    {
        if ($email === '' || !\Validate::isEmail($email)) {
            return false;
        }
        $method = (int) \Configuration::get('PS_MAIL_METHOD');
        if ($method === 3 || ($method === 1 && (string) ini_get('sendmail_path') === '')) {
            return false;
        }
        try {
            $langId = (int) \Configuration::get('PS_LANG_DEFAULT');
            $iso = (string) \Language::getIsoById($langId);
            if (!is_file(_PS_MODULE_DIR_ . 'marketplaceorders/mails/' . $iso . '/' . $template . '.html')) {
                $iso = 'en';
                $langId = (int) \Language::getIdByIso('en') ?: $langId;
            }
            if (!is_file(_PS_MODULE_DIR_ . 'marketplaceorders/mails/' . $iso . '/' . $template . '.html')) {
                return false;
            }

            return (bool) \Mail::Send(
                $langId,
                $template,
                $subject,
                $vars,
                $email,
                $name,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . 'marketplaceorders/mails/',
                false,
                (int) \Context::getContext()->shop->id
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function vendorEmail(int $vendorId): string
    {
        return (new Connection())->value(
            'SELECT `email` FROM ' . Connection::table('marketplace_vendor')
            . ' WHERE `id_marketplace_vendor` = ' . (int) $vendorId
        );
    }
}
