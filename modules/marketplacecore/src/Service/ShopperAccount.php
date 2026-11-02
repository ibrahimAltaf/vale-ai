<?php

declare(strict_types=1);

namespace Marketplace\Core\Service;

use Marketplace\Core\Database\Connection;
use Marketplace\Core\Domain\Money;
use Marketplace\Core\Install\SchemaInstaller;
use Marketplace\Core\Repository\OrderRepository;
use Marketplace\Orders\Mail\OrderMailer;

final class ShopperAccount
{
    public function __construct()
    {
        (new SchemaInstaller())->upgrade();
    }

    public function isLogged(): bool
    {
        return (bool) \Context::getContext()->customer->isLogged();
    }

    /**
     * @return array{id: int, firstname: string, lastname: string, email: string}|null
     */
    public function current(): ?array
    {
        $customer = \Context::getContext()->customer;
        if (!$customer->isLogged()) {
            return null;
        }

        return [
            'id' => (int) $customer->id,
            'firstname' => (string) $customer->firstname,
            'lastname' => (string) $customer->lastname,
            'email' => (string) $customer->email,
        ];
    }

    public function login(string $email, string $password): void
    {
        $customer = new \Customer();
        if (!$customer->getByEmail($email, $password) || !$customer->id || !(int) $customer->active) {
            throw new \InvalidArgumentException('Unknown email or password.');
        }
        \Context::getContext()->updateCustomer($customer);
    }

    public function register(string $firstname, string $lastname, string $email, string $password): void
    {
        $firstname = trim($firstname);
        $lastname = trim($lastname);
        $email = trim($email);
        if (!\Validate::isName($firstname) || !\Validate::isName($lastname)) {
            throw new \InvalidArgumentException('Enter your first and last name.');
        }
        if (!\Validate::isEmail($email)) {
            throw new \InvalidArgumentException('Enter a valid email address.');
        }
        if (\Customer::customerExists($email)) {
            throw new \InvalidArgumentException('An account with that email already exists. Sign in instead.');
        }
        $this->assertPassword($password);

        $crypto = \PrestaShop\PrestaShop\Adapter\ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');
        $customer = new \Customer();
        $customer->firstname = $firstname;
        $customer->lastname = $lastname;
        $customer->email = $email;
        $customer->passwd = $crypto->hash($password);
        $customer->active = 1;
        $customer->is_guest = 0;
        $customer->id_default_group = (int) \Configuration::get('PS_CUSTOMER_GROUP');
        if (!$customer->add()) {
            throw new \RuntimeException('The account could not be created.');
        }
        $customer->addGroups([(int) \Configuration::get('PS_CUSTOMER_GROUP')]);
        $this->login($email, $password);
    }

    /**
     * @return array{message: string, link: string}
     */
    public function requestReset(string $email): array
    {
        $email = trim($email);
        $message = 'If an account exists for that email, a reset link is on its way.';
        $customer = new \Customer();
        if (!\Validate::isEmail($email) || !$customer->getByEmail($email) || !$customer->id) {
            return ['message' => $message, 'link' => ''];
        }
        $customer->stampResetPasswordToken();
        $customer->update();
        $link = \Context::getContext()->link->getModuleLink('marketplaceorders', 'account', [
            'id_customer' => (int) $customer->id,
            'reset_token' => (string) $customer->reset_password_token,
        ]);
        $sent = (new OrderMailer())->sendReset($email, (string) $customer->firstname, $link);

        return [
            'message' => $sent ? $message : 'The shop could not send email, so the reset link is shown once on this page.',
            'link' => $sent ? '' : $link,
        ];
    }

    public function reset(int $id, string $token, string $password): void
    {
        $customer = new \Customer($id);
        if (!$customer->id || !hash_equals((string) $customer->reset_password_token, $token)) {
            throw new \InvalidArgumentException('This reset link is invalid.');
        }
        if (strtotime((string) $customer->reset_password_validity) < time()) {
            throw new \InvalidArgumentException('This reset link has expired.');
        }
        $this->assertPassword($password);
        $crypto = \PrestaShop\PrestaShop\Adapter\ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');
        $customer->passwd = $crypto->hash($password);
        $customer->reset_password_token = '';
        $minutes = (int) \Configuration::get('PS_PASSWD_TIME_FRONT');
        $customer->last_passwd_gen = date('Y-m-d H:i:s', strtotime('-' . max(1, $minutes) . ' minutes'));
        if (!$customer->update()) {
            throw new \RuntimeException('The password could not be saved.');
        }
    }

    public function logout(): void
    {
        $customer = \Context::getContext()->customer;
        if ($customer instanceof \Customer && method_exists($customer, 'logout')) {
            $customer->logout();

            return;
        }
        \Context::getContext()->cookie->logout();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function orders(int $customerId): array
    {
        $rows = [];
        foreach ((new OrderRepository(new Connection()))->forCustomer($customerId) as $order) {
            $rows[] = [
                'reference' => (string) $order['reference'],
                'date' => (string) $order['date_add'],
                'total' => Money::euros((int) $order['grand_cents']),
                'payment' => (string) ($order['payment_method'] ?? ''),
                'status' => $this->statusLabel((string) ($order['status'] ?? '')),
                'postcode' => (string) $order['postcode'],
                'url' => \Context::getContext()->link->getModuleLink('marketplaceorders', 'receipt', [
                    'reference' => (string) $order['reference'],
                ]),
            ];
        }

        return $rows;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Paid',
            'awaiting_payment' => 'Awaiting payment',
            'confirmed', 'completed' => 'Completed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function assertPassword(string $password): void
    {
        $length = \Validate::isAcceptablePasswordLength($password);
        $score = !method_exists(\Validate::class, 'isAcceptablePasswordScore') || \Validate::isAcceptablePasswordScore($password);
        if (!$length || !$score) {
            throw new \InvalidArgumentException('Use a longer password that is harder to guess.');
        }
    }
}
