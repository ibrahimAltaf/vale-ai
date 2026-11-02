<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'marketplacecore/src/Autoload.php';

use Marketplace\Core\Autoload;
use Marketplace\Core\Database\Connection;
use Marketplace\Core\Install\SchemaInstaller;
use Marketplace\Core\Repository\VendorRepository;
use Marketplace\Orders\Mail\OrderMailer;

class MarketplacevendorPasswordModuleFrontController extends ModuleFrontController
{
    private string $notice = '';

    private string $resetLink = '';

    public function __construct()
    {
        parent::__construct();
        Autoload::register();
        (new SchemaInstaller())->upgrade();
    }

    public function postProcess(): void
    {
        $submitted = Tools::isSubmit('submitVendorForgot') || Tools::isSubmit('submitVendorReset');
        if ($submitted && (string) Tools::getValue('token') !== Tools::getToken(false)) {
            $this->errors[] = 'The form token expired. Reload the page and try again.';

            return;
        }

        $vendors = new VendorRepository(new Connection());
        try {
            if (Tools::isSubmit('submitVendorForgot')) {
                $email = trim((string) Tools::getValue('email'));
                $vendor = $vendors->findByEmail($email);
                $this->notice = 'If a vendor account exists for that email, a reset link is on its way.';
                if ($vendor !== null && (string) $vendor['status'] === 'active') {
                    $resetToken = $vendors->createPasswordReset((int) $vendor['id_marketplace_vendor']);
                    $link = $this->context->link->getModuleLink('marketplacevendor', 'password', ['reset' => $resetToken]);
                    $sent = (new OrderMailer())->sendReset($email, (string) $vendor['name'], $link);
                    if (!$sent) {
                        $this->notice = 'The shop could not send email, so the reset link is shown once on this page.';
                        $this->resetLink = $link;
                    }
                }
            }
            if (Tools::isSubmit('submitVendorReset')) {
                $password = (string) Tools::getValue('password');
                if ($password !== (string) Tools::getValue('password_confirm')) {
                    throw new InvalidArgumentException('The passwords do not match.');
                }
                if (strlen($password) < 8) {
                    throw new InvalidArgumentException('Use at least 8 characters.');
                }
                $reset = $vendors->findPasswordReset((string) Tools::getValue('reset_token'));
                if ($reset === null) {
                    throw new InvalidArgumentException('This reset link is invalid or has expired.');
                }
                $vendors->setPassword((int) $reset['id_marketplace_vendor'], password_hash($password, PASSWORD_DEFAULT));
                $this->notice = 'Password updated. Sign in with the new password.';
            }
        } catch (Throwable $exception) {
            $this->errors[] = $exception->getMessage();
        }
    }

    public function initContent(): void
    {
        parent::initContent();
        $resetToken = (string) Tools::getValue('reset_token', Tools::getValue('reset'));
        if (Tools::isSubmit('submitVendorReset') && $this->errors === []) {
            $resetToken = '';
        }
        $this->context->smarty->assign([
            'mp_token' => Tools::getToken(false),
            'mp_notice' => $this->notice,
            'mp_reset_link' => $this->resetLink,
            'mp_errors' => $this->errors,
            'mp_reset_token' => $resetToken,
            'mp_vendor_url' => $this->context->link->getModuleLink('marketplacevendor', 'dashboard'),
        ]);
        $this->setTemplate('module:marketplacevendor/views/templates/front/password.tpl');
    }
}
