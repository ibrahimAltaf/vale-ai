<?php

declare(strict_types=1);

namespace Marketplace\Platform\Api;

/**
 * Single route catalog for the API server, OpenAPI document and Postman collection.
 */
final class RouteMap
{
    /**
     * @return list<array{method: string, path: string, name: string, roles: list<string>, permission: string, tag: string, summary: string}>
     */
    public static function routes(): array
    {
        return [
            self::route('GET', '/api/v1/health', 'health', [], 'health.read', 'Health', 'Service health and payment-provider mode.'),
            self::route('POST', '/api/v1/auth/register/vendor', 'registerVendor', [], 'auth.register', 'Authentication', 'Register a vendor. The account stays pending until an administrator approves it.'),
            self::route('POST', '/api/v1/auth/register/customer', 'registerCustomer', [], 'auth.register', 'Authentication', 'Register a customer account.'),
            self::route('POST', '/api/v1/auth/login', 'login', [], 'auth.login', 'Authentication', 'Exchange an email and password for a bearer token.'),
            self::route('POST', '/api/v1/auth/logout', 'logout', ['shopper', 'vendor', 'admin'], 'auth.logout', 'Authentication', 'Revoke the presented bearer token.'),
            self::route('POST', '/api/v1/auth/password-reset', 'passwordReset', [], 'auth.reset', 'Authentication', 'Request a password reset without revealing whether the account exists.'),
            self::route('POST', '/api/v1/auth/password-reset/confirm', 'passwordConfirm', [], 'auth.reset', 'Authentication', 'Set a new password with a reset token.'),
            self::route('GET', '/api/v1/customers/me', 'customerMe', ['shopper'], 'customer.read', 'Customers', 'Read the authenticated customer and any linked storefront id.'),
            self::route('POST', '/api/v1/customers/me/link-challenges', 'linkChallenge', ['shopper'], 'customer.link', 'Customers', 'Start a link to a PrestaShop customer. Email alone is not accepted.'),
            self::route('GET', '/api/v1/vendors', 'vendorSearch', [], 'vendors.search', 'Vendors', 'Search active public vendor profiles by name.'),
            self::route('GET', '/api/v1/vendors/me', 'vendorMe', ['vendor'], 'vendor.read', 'Vendors', 'Read the authenticated vendor profile.'),
            self::route('PUT', '/api/v1/vendors/me', 'updateVendor', ['vendor'], 'vendor.update', 'Vendors', 'Update the authenticated vendor profile.'),
            self::route('POST', '/api/v1/vendors/me/logo', 'uploadLogo', ['vendor'], 'vendor.logo', 'Vendors', 'Upload a PNG or JPEG logo.'),
            self::route('GET', '/api/v1/vendors/{id}', 'publicVendor', [], 'vendors.read', 'Vendors', 'Read one active public vendor profile.'),
            self::route('POST', '/api/v1/vendors/me/shipping-rules', 'shippingRule', ['vendor'], 'shipping.write', 'Shipping', 'Create or update a vendor shipping rule.'),
            self::route('GET', '/api/v1/catalog/products', 'products', ['shopper', 'vendor', 'admin'], 'catalog.read', 'Catalog', 'List master products that have an active offer.'),
            self::route('GET', '/api/v1/offers', 'offers', ['shopper', 'vendor', 'admin'], 'offers.read', 'Offers', 'List vendor offers.'),
            self::route('POST', '/api/v1/vendors/me/offers', 'upsertOffer', ['vendor'], 'offers.write', 'Offers', 'Create or update an offer for the authenticated vendor.'),
            self::route('GET', '/api/v1/inventory', 'inventory', ['vendor'], 'inventory.read', 'Inventory', 'List the authenticated vendor offers and stock.'),
            self::route('PUT', '/api/v1/inventory/{offerId}', 'setStock', ['vendor'], 'inventory.write', 'Inventory', 'Set the on-hand quantity for an offer.'),
            self::route('POST', '/api/v1/shipping/quote', 'quote', ['shopper', 'vendor', 'admin'], 'shipping.quote', 'Shipping', 'Quote one vendor shipment.'),
            self::route('POST', '/api/v1/cart/optimize', 'optimize', ['shopper', 'vendor', 'admin'], 'cart.optimize', 'Smart Cart', 'Find the cheapest eligible vendor allocation.'),
            self::route('POST', '/api/v1/cart/validate', 'validate', ['shopper', 'vendor', 'admin'], 'cart.validate', 'Smart Cart', 'Validate a cart without reserving stock.'),
            self::route('POST', '/api/v1/checkout', 'checkout', ['shopper'], 'checkout.create', 'Checkout', 'Reserve stock and create a parent order plus vendor sub-orders.'),
            self::route('GET', '/api/v1/orders', 'orders', ['shopper'], 'orders.read', 'Orders', 'List the authenticated customer orders.'),
            self::route('GET', '/api/v1/orders/{id}', 'order', ['shopper'], 'orders.read', 'Orders', 'Read one customer order.'),
            self::route('POST', '/api/v1/orders/{id}/cancel', 'cancelOrder', ['shopper'], 'orders.cancel', 'Orders', 'Cancel an unpaid order and release its reservation.'),
            self::route('GET', '/api/v1/vendors/me/orders', 'vendorOrders', ['vendor'], 'vendor.orders.read', 'Orders', 'List the authenticated vendor sub-orders.'),
            self::route('POST', '/api/v1/vendors/me/orders/{id}/fulfill', 'fulfill', ['vendor'], 'vendor.orders.fulfill', 'Orders', 'Fulfill a paid vendor sub-order.'),
            self::route('POST', '/api/v1/payments', 'pay', ['shopper'], 'payments.create', 'Payments', 'Take a tokenized card payment for an order.'),
            self::route('GET', '/api/v1/payments/{id}', 'payment', ['shopper', 'admin'], 'payments.read', 'Payments', 'Read a payment.'),
            self::route('POST', '/api/v1/mangopay/webhooks', 'webhook', [], 'webhooks.receive', 'Mangopay', 'Receive a Mangopay event and verify it with the provider.'),
            self::route('POST', '/api/v1/vendors/me/mangopay/identity', 'identity', ['vendor'], 'mangopay.identity', 'Mangopay', 'Create the vendor Mangopay user and wallet.'),
            self::route('POST', '/api/v1/vendors/me/mangopay/kyc', 'kyc', ['vendor'], 'mangopay.kyc', 'Mangopay', 'Submit vendor KYC.'),
            self::route('GET', '/api/v1/vendors/me/mangopay/wallets', 'wallets', ['vendor'], 'mangopay.wallets', 'Mangopay', 'List vendor wallets.'),
            self::route('POST', '/api/v1/admin/refunds', 'refund', ['admin'], 'refunds.create', 'Refunds', 'Refund a paid order in full or in part.'),
            self::route('POST', '/api/v1/vendors/me/payouts', 'payout', ['vendor'], 'payouts.request', 'Payouts', 'Request a payout of the available balance.'),
            self::route('GET', '/api/v1/vendors/me/payouts', 'payouts', ['vendor'], 'payouts.read', 'Payouts', 'List vendor payouts.'),
            self::route('POST', '/api/v1/admin/payouts/{id}/process', 'processPayout', ['admin'], 'payouts.process', 'Payouts', 'Send a requested payout to the provider.'),
            self::route('GET', '/api/v1/vendors/me/commissions', 'commissions', ['vendor'], 'commissions.read', 'Commissions', 'Read the vendor commission ledger and available balance.'),
            self::route('GET', '/api/v1/vendors/me/statements', 'statement', ['vendor'], 'statements.read', 'Commissions', 'Read a monthly commission statement.'),
            self::route('GET', '/api/v1/vendors/me/statements/export', 'exportStatement', ['vendor'], 'statements.export', 'Commissions', 'Export a monthly commission statement as CSV.'),
            self::route('POST', '/api/v1/vendors/me/credit-notes', 'creditNote', ['vendor'], 'invoices.credit', 'Invoices', 'Create a credit note for a refunded shipment.'),
            self::route('GET', '/api/v1/admin/reconciliation', 'reconciliation', ['admin'], 'reconciliation.read', 'Admin', 'Read the financial reconciliation ledger.'),
            self::route('GET', '/api/v1/admin/audit', 'audit', ['admin'], 'audit.read', 'Admin', 'Read the audit log.'),
            self::route('POST', '/api/v1/vendors/me/imports', 'import', ['vendor'], 'imports.create', 'CSV Imports', 'Import offers from a CSV document.'),
            self::route('GET', '/api/v1/vendors/me/imports', 'imports', ['vendor'], 'imports.read', 'CSV Imports', 'List vendor import jobs.'),
            self::route('POST', '/api/v1/vendors/me/import-schedules', 'scheduleImport', ['vendor'], 'imports.schedule', 'CSV Imports', 'Schedule a CSV import for cron.'),
            self::route('GET', '/api/v1/vendors/me/exports/offers', 'exportOffers', ['vendor'], 'exports.offers', 'CSV Imports', 'Export vendor offers as CSV.'),
            self::route('GET', '/api/v1/vendors/me/exports/orders', 'exportOrders', ['vendor'], 'exports.orders', 'CSV Imports', 'Export vendor orders as CSV.'),
            self::route('POST', '/api/v1/vendors/me/invoices', 'createInvoice', ['vendor'], 'invoices.create', 'Invoices', 'Create a pending customer invoice for a vendor sub-order.'),
            self::route('POST', '/api/v1/invoices/{id}/issue', 'issueInvoice', ['vendor', 'admin'], 'invoices.issue', 'Invoices', 'Mark an invoice as issued.'),
            self::route('POST', '/api/v1/invoices/{id}/upload', 'uploadInvoice', ['vendor', 'admin'], 'invoices.upload', 'Invoices', 'Upload the invoice PDF.'),
            self::route('GET', '/api/v1/invoices', 'invoices', ['shopper', 'vendor', 'admin'], 'invoices.read', 'Invoices', 'List invoices visible to the caller.'),
            self::route('GET', '/api/v1/invoices/{id}', 'invoice', ['shopper', 'vendor', 'admin'], 'invoices.read', 'Invoices', 'Read one invoice.'),
            self::route('GET', '/api/v1/invoices/{id}/download', 'downloadInvoice', ['shopper', 'vendor', 'admin'], 'invoices.download', 'Invoices', 'Download an issued invoice file.'),
            self::route('POST', '/api/v1/admin/invoices/commission', 'commissionInvoice', ['admin'], 'invoices.commission', 'Invoices', 'Create the marketplace commission invoice for a vendor sub-order.'),
            self::route('GET', '/api/v1/admin/vendors', 'adminVendors', ['admin'], 'admin.vendors.read', 'Admin', 'List vendors.'),
            self::route('POST', '/api/v1/admin/vendors/{id}/approve', 'approveVendor', ['admin'], 'admin.vendors.approve', 'Admin', 'Approve a pending vendor.'),
            self::route('POST', '/api/v1/admin/vendors/{id}/suspend', 'suspendVendor', ['admin'], 'admin.vendors.suspend', 'Admin', 'Suspend a vendor.'),
            self::route('POST', '/api/v1/admin/vendors/{id}/commission', 'setCommission', ['admin'], 'admin.vendors.commission', 'Admin', 'Set a vendor commission rate.'),
            self::route('POST', '/api/v1/admin/vendors/{id}/vat', 'setVat', ['admin'], 'admin.vendors.vat', 'Admin', 'Set the VAT rate applied to commission.'),
            self::route('POST', '/api/v1/admin/customers/links', 'confirmLink', ['admin'], 'admin.customers.link', 'Customers', 'Confirm a customer link with the one-time code. Email matching is not used.'),
            self::route('GET', '/api/v1/admin/orders', 'adminOrders', ['admin'], 'admin.orders.read', 'Admin', 'List marketplace orders.'),
            self::route('GET', '/api/v1/admin/permissions', 'permissions', ['admin'], 'admin.permissions.read', 'Admin', 'List API role permissions.'),
            self::route('POST', '/api/v1/admin/maintenance', 'maintenance', ['admin'], 'admin.maintenance', 'Admin', 'Release expired reservations and run due imports.'),
        ];
    }

    /**
     * @return array{method: string, path: string, name: string, roles: list<string>, permission: string, tag: string, summary: string, params: array<string, string>}|null
     */
    public static function match(string $method, string $path): ?array
    {
        $path = self::normalize($path);
        foreach (self::routes() as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            $pattern = preg_replace('#\{[A-Za-z]+\}#', '([^/]+)', $route['path']) ?? $route['path'];
            if (!preg_match('#^' . $pattern . '$#', $path, $matches)) {
                continue;
            }
            preg_match_all('#\{([A-Za-z]+)\}#', $route['path'], $names);
            $params = [];
            foreach ($names[1] as $index => $name) {
                $params[$name] = $matches[$index + 1];
            }
            $route['params'] = $params;

            return $route;
        }

        return null;
    }

    public static function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return '/api/v1/health';
        }
        if (!str_starts_with($path, '/api/v1')) {
            $path = '/api/v1' . ($path === '/' ? '' : $path);
        }

        return rtrim($path, '/') ?: '/api/v1';
    }

    /**
     * @param list<string> $roles
     * @return array{method: string, path: string, name: string, roles: list<string>, permission: string, tag: string, summary: string}
     */
    private static function route(string $method, string $path, string $name, array $roles, string $permission, string $tag, string $summary): array
    {
        return [
            'method' => $method,
            'path' => $path,
            'name' => $name,
            'roles' => $roles,
            'permission' => $permission,
            'tag' => $tag,
            'summary' => $summary,
        ];
    }
}
