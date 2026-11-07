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
        $text = static fn (bool $required, int $max = 255, int $min = 0): array => ['required' => $required, 'type' => 'string', 'max' => $max, 'min' => $min];
        $int = static fn (bool $required, bool $nullable = false, int $min = 0, int $max = 100000000): array => ['required' => $required, 'type' => 'int', 'nullable' => $nullable, 'min' => $min, 'max' => $max];
        $bool = static fn (bool $required): array => ['required' => $required, 'type' => 'bool'];
        $cart = ['postcode' => $text(true, 12, 2), 'lines' => ['required' => true, 'type' => 'lines']];

        return [
            'registerVendor' => ['name' => $text(true), 'email' => $text(true), 'password' => $text(true), 'code' => $text(false)],
            'registerCustomer' => ['email' => $text(true), 'password' => $text(true), 'first_name' => $text(true), 'last_name' => $text(true)],
            'login' => ['role' => ['required' => true, 'type' => 'string', 'max' => 16, 'enum' => ['shopper', 'vendor', 'admin']], 'email' => $text(true), 'password' => $text(true, 128, 12)],
            'passwordReset' => ['role' => ['required' => true, 'type' => 'string', 'max' => 16, 'enum' => ['shopper', 'vendor']], 'email' => $text(true)],
            'passwordConfirm' => ['role' => ['required' => true, 'type' => 'string', 'max' => 16, 'enum' => ['shopper', 'vendor']], 'email' => $text(true), 'token' => $text(true, 64, 32), 'password' => $text(true, 128, 12)],
            'linkChallenge' => ['id_customer' => $int(true, false, 1)],
            'confirmLink' => ['shopper_id' => $int(true, false, 1), 'id_customer' => $int(true, false, 1), 'code' => $text(true, 16, 16)],
            'updateVendor' => ['name' => $text(false), 'description' => $text(false, 2000), 'contact_name' => $text(false), 'phone' => $text(false, 64), 'city' => $text(false, 128), 'brands' => $text(false), 'coverage' => $text(false), 'min_order_enabled' => $bool(false), 'min_order_cents' => $int(false)],
            'shippingRule' => [
                'name' => $text(true),
                'base_cents' => $int(true),
                'free_threshold_cents' => $int(false, true),
                'surcharge_cents_per_kg' => $int(true),
                'postcode_prefixes' => ['required' => true, 'type' => 'prefixes'],
                'pallet_base_cents' => $int(false),
                'pallet_free_threshold_cents' => $int(false, true),
                'parcel_max_weight_grams' => $int(false, true),
                'parcel_max_volume_cm3' => $int(false, true),
                'pallet_capacity_weight_grams' => $int(false, true),
                'pallet_capacity_volume_cm3' => $int(false, true),
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
                'volume_cm3' => $int(false),
                'pallet_only' => $bool(false),
                'image_url' => $text(false, 512),
            ],
            'setStock' => ['quantity' => $int(true)],
            'quote' => ['vendor_id' => $int(true, false, 1), 'postcode' => $text(true, 12, 2), 'product_cents' => $int(true), 'weight_grams' => $int(true), 'volume_cm3' => $int(false), 'pallet_only' => $bool(false)],
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
            'uploadInvoice' => ['content_base64' => $text(true, 8000000)],
            'uploadLogo' => ['content_base64' => $text(true, 2000000)],
            'setCommission' => ['commission_percent' => $text(true, 8, 1)],
            'setVat' => ['vat_rate_percent' => $text(true, 8, 1)],
            'creditNote' => ['vendor_order_id' => $int(true, false, 1)],
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
            $max = (int) ($rule['max'] ?? 0);
            $min = (int) ($rule['min'] ?? 0);
            if ($max > 0 && strlen($value) > $max) {
                throw new PlatformException('validation_error', $key . ' is too long.', 422);
            }
            if ($value !== '' && $min > 0 && strlen($value) < $min) {
                throw new PlatformException('validation_error', $key . ' is too short.', 422);
            }
            if (isset($rule['enum']) && is_array($rule['enum']) && !in_array($value, $rule['enum'], true)) {
                throw new PlatformException('validation_error', $key . ' is invalid.', 422);
            }

            return;
        }
        if ($type === 'int' && is_int($value)) {
            if (isset($rule['min']) && $value < (int) $rule['min']) {
                throw new PlatformException('validation_error', $key . ' is below the minimum.', 422);
            }
            if (isset($rule['max']) && $value > (int) $rule['max']) {
                throw new PlatformException('validation_error', $key . ' is above the maximum.', 422);
            }

            return;
        }
        if ($type === 'bool' && is_bool($value)) {
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
                if ($key === 'quantity' && ($item < 1 || $item > 999)) {
                    throw new PlatformException('validation_error', 'quantity is out of range.', 422);
                }
                if ($key === 'product_id' && $item < 1) {
                    throw new PlatformException('validation_error', 'product_id is out of range.', 422);
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
