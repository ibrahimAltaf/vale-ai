<?php

declare(strict_types=1);

namespace Marketplace\Platform\Api;

use Marketplace\Platform\PlatformException;

/**
 * Rejects unknown and protected fields before a route reads the body.
 * Webhook bodies may contain extra provider fields. Those fields are not persisted as attributes.
 */
final class Payload
{
    /**
     * @param array<string, mixed> $body
     */
    public static function check(string $route, array $body): void
    {
        if ($route === 'webhook') {
            self::requireKeys($body, ['EventType', 'RessourceId']);

            return;
        }

        $schema = self::schemas()[$route] ?? null;
        if ($schema === null) {
            if ($body !== []) {
                throw new PlatformException('unexpected_field', 'The request contains a field this route does not accept.', 422);
            }

            return;
        }

        foreach ($body as $key => $value) {
            if (!isset($schema[$key])) {
                throw new PlatformException('unexpected_field', 'The field ' . $key . ' is not accepted.', 422);
            }
            self::value($key, $value, $schema[$key]);
        }
        foreach ($schema as $key => $rule) {
            if (($rule['required'] ?? false) === true && !array_key_exists($key, $body)) {
                throw new PlatformException('validation_error', $key . ' is required.', 422);
            }
        }
    }

    /**
     * @param array<string, array{required?: bool, type: string, nullable?: bool}> $ignored
     * @return array<string, array<string, array{required?: bool, type: string, nullable?: bool}>>
     */
    private static function schemas(): array
    {
        $text = static fn (bool $required): array => ['required' => $required, 'type' => 'string'];
        $int = static fn (bool $required, bool $nullable = false): array => ['required' => $required, 'type' => 'int', 'nullable' => $nullable];
        $cart = ['postcode' => $text(true), 'lines' => ['required' => true, 'type' => 'lines']];

        return [
            'registerVendor' => ['name' => $text(true), 'email' => $text(true), 'password' => $text(true), 'code' => $text(false)],
            'registerCustomer' => ['email' => $text(true), 'password' => $text(true), 'first_name' => $text(true), 'last_name' => $text(true)],
            'login' => ['role' => $text(true), 'email' => $text(true), 'password' => $text(true)],
            'updateVendor' => ['name' => $text(false), 'description' => $text(false), 'contact_name' => $text(false), 'phone' => $text(false)],
            'shippingRule' => [
                'name' => $text(true),
                'base_cents' => $int(true),
                'free_threshold_cents' => $int(false, true),
                'surcharge_cents_per_kg' => $int(true),
                'postcode_prefixes' => ['required' => true, 'type' => 'prefixes'],
            ],
            'upsertOffer' => [
                'product_id' => $int(false),
                'sku' => $text(true),
                'ean' => $text(false),
                'brand' => $text(false),
                'title' => $text(true),
                'description' => $text(false),
                'price_cents' => $int(true),
                'discount' => $text(false),
                'stock' => $int(true),
                'weight_grams' => $int(false),
                'image_url' => $text(false),
            ],
            'setStock' => ['quantity' => $int(true)],
            'quote' => ['vendor_id' => $int(true), 'postcode' => $text(true), 'product_cents' => $int(true), 'weight_grams' => $int(true)],
            'optimize' => $cart,
            'validate' => $cart,
            'checkout' => $cart,
            'pay' => ['order_id' => $int(true), 'card_id' => $text(true), 'return_url' => $text(true)],
            'identity' => [
                'legal_type' => $text(true),
                'first_name' => $text(true),
                'last_name' => $text(true),
                'country' => $text(true),
                'nationality' => $text(true),
                'birthday' => $text(true),
            ],
            'refund' => ['order_id' => $int(true), 'amount_cents' => $int(true)],
            'payout' => ['amount_cents' => $int(true)],
            'import' => ['filename' => $text(true), 'csv' => $text(true)],
            'scheduleImport' => ['filename' => $text(true), 'csv' => $text(true), 'interval_minutes' => $int(true)],
            'createInvoice' => ['vendor_order_id' => $int(true)],
            'commissionInvoice' => ['vendor_order_id' => $int(true)],
            'uploadInvoice' => ['content_base64' => $text(true)],
            'setCommission' => ['commission_percent' => $text(true)],
        ];
    }

    /**
     * @param array{required?: bool, type: string, nullable?: bool} $rule
     */
    private static function value(string $key, mixed $value, array $rule): void
    {
        if ($value === null) {
            if (($rule['nullable'] ?? false) === true) {
                return;
            }
            throw new PlatformException('validation_error', $key . ' cannot be null.', 422);
        }
        $type = $rule['type'];
        if ($type === 'string' && is_string($value)) {
            return;
        }
        if ($type === 'int' && is_int($value)) {
            return;
        }
        if ($type === 'lines') {
            self::lines($value);

            return;
        }
        if ($type === 'prefixes') {
            self::prefixes($value);

            return;
        }
        throw new PlatformException('validation_error', $key . ' has the wrong type.', 422);
    }

    private static function lines(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new PlatformException('validation_error', 'lines must be a list.', 422);
        }
        foreach ($value as $line) {
            if (!is_array($line)) {
                throw new PlatformException('validation_error', 'Each cart line must be an object.', 422);
            }
            foreach ($line as $key => $item) {
                if (!in_array($key, ['product_id', 'quantity'], true)) {
                    throw new PlatformException('unexpected_field', 'The field ' . $key . ' is not accepted.', 422);
                }
                if (!is_int($item)) {
                    throw new PlatformException('validation_error', $key . ' has the wrong type.', 422);
                }
            }
            if (!isset($line['product_id'], $line['quantity'])) {
                throw new PlatformException('validation_error', 'Each cart line needs a product and a quantity.', 422);
            }
        }
    }

    private static function prefixes(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new PlatformException('validation_error', 'Postcode prefixes must be a list.', 422);
        }
        foreach ($value as $prefix) {
            if (!is_string($prefix)) {
                throw new PlatformException('validation_error', 'A postcode prefix is invalid.', 422);
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $keys
     */
    private static function requireKeys(array $body, array $keys): void
    {
        foreach ($keys as $key) {
            if (!isset($body[$key]) || !is_string($body[$key]) || $body[$key] === '') {
                throw new PlatformException('validation_error', $key . ' is required.', 422);
            }
        }
    }
}
