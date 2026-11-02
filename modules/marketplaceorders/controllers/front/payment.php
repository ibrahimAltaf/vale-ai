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
use Marketplace\Core\Service\ShopperAccount;
use Marketplace\Orders\Service\CardPayment;

class MarketplaceordersPaymentModuleFrontController extends ModuleFrontController
{
    private string $notice = '';

    private string $resetLink = '';

    public function __construct()
    {
        parent::__construct();
        Autoload::register();
    }

    public function postProcess(): void
    {
        $submitted = Tools::isSubmit('submitLogin') || Tools::isSubmit('submitRegister')
            || Tools::isSubmit('submitForgot') || Tools::isSubmit('submitPay');
        if ($submitted && (string) Tools::getValue('token') !== Tools::getToken(false)) {
            $this->errors[] = 'The form token expired. Reload the page and try again.';

            return;
        }

        $account = new ShopperAccount();
        try {
            if (Tools::isSubmit('submitLogin')) {
                $account->login((string) Tools::getValue('email'), (string) Tools::getValue('password'));
                $this->notice = 'Signed in.';
            }
            if (Tools::isSubmit('submitRegister')) {
                $account->register(
                    (string) Tools::getValue('firstname'),
                    (string) Tools::getValue('lastname'),
                    (string) Tools::getValue('email'),
                    (string) Tools::getValue('password'),
                );
                $this->notice = 'Account created.';
            }
            if (Tools::isSubmit('submitForgot')) {
                $reset = $account->requestReset((string) Tools::getValue('email'));
                $this->notice = $reset['message'];
                $this->resetLink = $reset['link'];
            }
            if (Tools::isSubmit('submitPay')) {
                $this->pay($account);
            }
        } catch (Throwable $exception) {
            $this->errors[] = $exception->getMessage();
        }
    }

    public function initContent(): void
    {
        parent::initContent();
        [$lines, $postcode] = $this->cartState();
        $result = null;
        if ($lines !== []) {
            try {
                $result = (new MarketplaceApp())->optimizeLines($lines, $postcode);
            } catch (Throwable $exception) {
                $this->errors[] = $exception->getMessage();
            }
        }
        $account = new ShopperAccount();
        $this->context->smarty->assign([
            'mp_token' => Tools::getToken(false),
            'mp_postcode' => $postcode,
            'mp_result' => $result,
            'mp_customer' => $account->current(),
            'mp_notice' => $this->notice,
            'mp_reset_link' => $this->resetLink,
            'mp_errors' => $this->errors,
            'mp_cart_url' => $this->context->link->getModuleLink('marketplaceoptimizer', 'cart'),
            'mp_account_url' => $this->context->link->getModuleLink('marketplaceorders', 'account'),
        ]);
        $this->setTemplate('module:marketplaceorders/views/templates/front/payment.tpl');
    }

    private function pay(ShopperAccount $account): void
    {
        $customer = $account->current();
        if ($customer === null) {
            throw new InvalidArgumentException('Sign in before paying.');
        }
        [$lines, $postcode] = $this->cartState();
        if ($lines === []) {
            throw new InvalidArgumentException('The cart is empty.');
        }
        $method = (string) Tools::getValue('payment_method');
        $checkout = [
            'id_customer' => $customer['id'],
            'payment_method' => $method,
        ];
        if ($method === 'card') {
            $card = (new CardPayment())->authorize(
                (string) Tools::getValue('card_number'),
                (string) Tools::getValue('card_expiry'),
                (string) Tools::getValue('card_cvc'),
                (string) Tools::getValue('card_holder'),
            );
            $checkout['card_last4'] = $card['last4'];
            $checkout['card_brand'] = $card['brand'];
            $checkout['card_expiry'] = $card['expiry'];
            $checkout['card_holder'] = $card['holder'];
        } elseif ($method !== 'bankwire') {
            throw new InvalidArgumentException('Choose card or bank transfer.');
        }

        $placed = (new MarketplaceApp())->confirmLines($lines, $postcode, $checkout);
        $this->clearCart();
        $this->context->cookie->mp_mail = $placed['email_sent'] ? '1' : '0';
        $this->context->cookie->mp_shop_error = (string) ($placed['shop_error'] ?? '');
        $this->context->cookie->write();
        Tools::redirect($this->context->link->getModuleLink('marketplaceorders', 'receipt', [
            'reference' => $placed['reference'],
        ]));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function cartState(): array
    {
        $token = (string) ($this->context->cookie->mp_cart ?? '');
        if ($token === '') {
            return [[], '10115'];
        }
        $cart = new CartRepository(new Connection());
        $cartId = $cart->findOrCreate($token, '10115');
        $postcode = $cart->postcode($cartId);
        if ($postcode === '') {
            $postcode = '10115';
        }

        return [$cart->lines($cartId), $postcode];
    }

    private function clearCart(): void
    {
        $token = (string) ($this->context->cookie->mp_cart ?? '');
        if ($token === '') {
            return;
        }
        $cart = new CartRepository(new Connection());
        $cart->clear($cart->findOrCreate($token, '10115'));
    }
}
