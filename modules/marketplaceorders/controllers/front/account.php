<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;
use Marketplace\Core\Service\ShopperAccount;

class MarketplaceordersAccountModuleFrontController extends ModuleFrontController
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
        $account = new ShopperAccount();
        if (Tools::isSubmit('submitLogout')) {
            $account->logout();
            Tools::redirect($this->context->link->getModuleLink('marketplaceorders', 'account'));
        }

        $submitted = Tools::isSubmit('submitLogin') || Tools::isSubmit('submitRegister')
            || Tools::isSubmit('submitForgot') || Tools::isSubmit('submitReset');
        if ($submitted && (string) Tools::getValue('token') !== Tools::getToken(false)) {
            $this->errors[] = 'The form token expired. Reload the page and try again.';

            return;
        }

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
            if (Tools::isSubmit('submitReset')) {
                if ((string) Tools::getValue('password') !== (string) Tools::getValue('password_confirm')) {
                    throw new InvalidArgumentException('The passwords do not match.');
                }
                $account->reset(
                    (int) Tools::getValue('id_customer'),
                    (string) Tools::getValue('reset_token'),
                    (string) Tools::getValue('password'),
                );
                $this->notice = 'Password updated. Sign in with the new password.';
            }
        } catch (Throwable $exception) {
            $this->errors[] = $exception->getMessage();
        }
    }

    public function initContent(): void
    {
        parent::initContent();
        $account = new ShopperAccount();
        $customer = $account->current();
        $resetId = (int) Tools::getValue('id_customer');
        $resetToken = (string) Tools::getValue('reset_token');
        if (Tools::isSubmit('submitReset') && $this->errors === []) {
            $resetId = 0;
            $resetToken = '';
        }
        $this->context->smarty->assign([
            'mp_token' => Tools::getToken(false),
            'mp_customer' => $customer,
            'mp_orders' => $customer === null ? [] : $account->orders($customer['id']),
            'mp_notice' => $this->notice,
            'mp_reset_link' => $this->resetLink,
            'mp_errors' => $this->errors,
            'mp_reset_id' => $resetId,
            'mp_reset_token' => $resetToken,
            'mp_cart_url' => $this->context->link->getModuleLink('marketplaceoptimizer', 'cart'),
        ]);
        $this->setTemplate('module:marketplaceorders/views/templates/front/account.tpl');
    }
}
