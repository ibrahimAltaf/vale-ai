<?php

declare(strict_types=1);

namespace Marketplace\Platform;

use PDO;
use PDOException;
use Marketplace\Catalog\Dto\CatalogProduct;
use Marketplace\Catalog\Dto\ImportRow;
use Marketplace\Catalog\Dto\MatchType;
use Marketplace\Catalog\Service\CsvOfferParser;
use Marketplace\Catalog\Service\ProductMatcher;
use Marketplace\Core\Cache\RedisClient;
use Marketplace\Core\Domain\Money;
use Marketplace\Core\Domain\OptimizationStatus;
use Marketplace\Optimizer\Dto\AllocationLine;
use Marketplace\Optimizer\Dto\CartInput;
use Marketplace\Optimizer\Dto\CartLine;
use Marketplace\Optimizer\Dto\OfferOption;
use Marketplace\Optimizer\Dto\OptimizationResult;
use Marketplace\Optimizer\Dto\VendorAllocation;
use Marketplace\Optimizer\Service\CartOptimizer;
use Marketplace\Platform\Mangopay\Gateway;
use Marketplace\Shipping\Dto\ShippingRule;
use Marketplace\Shipping\Service\ShippingCalculator;
use Marketplace\Shipping\Service\ShippingRuleSelector;

/**
 * Marketplace backend used by the versioned API and the cron runner.
 * Money is integer cents. The smart-cart optimizer is the existing one.
 */
final class Platform
{
    use PlatformFinance;

    private static ?string $dummyHash = null;

    public function __construct(
        protected readonly Store $store,
        protected readonly Gateway $gateway,
        private readonly string $invoiceDirectory,
        private readonly string $adminEmail,
        private readonly string $adminPasswordHash,
        private readonly int $rateLimit = 120,
        private readonly int $maxCombinations = 200000,
        private ?NativeOrderPort $nativeOrders = null,
        private bool $exposeResetTokens = false,
    ) {
        if (!is_dir($this->invoiceDirectory) && !mkdir($this->invoiceDirectory, 0700, true) && !is_dir($this->invoiceDirectory)) {
            throw new PlatformException('storage_unavailable', 'Invoice storage is not available.', 500);
        }
    }

    public static function open(
        string $sqliteFile,
        Gateway $gateway,
        string $adminEmail = '',
        string $adminPasswordHash = '',
        ?string $invoiceDirectory = null,
        int $rateLimit = 120,
        int $maxCombinations = 200000,
    ): self {
        $pdo = new PDO('sqlite:' . $sqliteFile);
        Schema::apply($pdo, 'ps_');

        return new self(
            new Store($pdo, 'ps_'),
            $gateway,
            $invoiceDirectory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp-invoices-' . bin2hex(random_bytes(4)),
            $adminEmail,
            $adminPasswordHash,
            $rateLimit,
            $maxCombinations,
        );
    }

    public static function connect(
        PDO $pdo,
        string $prefix,
        Gateway $gateway,
        string $invoiceDirectory,
        string $adminEmail = '',
        string $adminPasswordHash = '',
    ): self {
        Schema::apply($pdo, $prefix);

        return new self(new Store($pdo, $prefix), $gateway, $invoiceDirectory, $adminEmail, $adminPasswordHash);
    }

    public function setNativeOrderPort(?NativeOrderPort $port): void
    {
        $this->nativeOrders = $port;
    }

    public function setExposeResetTokens(bool $expose): void
    {
        $this->exposeResetTokens = $expose;
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return [
            'status' => 'ok',
            'payment_mode' => $this->gateway->mode(),
            'provider_configured' => $this->gateway->mode() === 'sandbox',
            'live_provider_verified' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function registerVendor(array $input): array
    {
        $email = $this->email((string) ($input['email'] ?? ''));
        $name = $this->requiredText($input, 'name', 2, 255);
        $password = $this->password((string) ($input['password'] ?? ''));
        $code = isset($input['code']) && (string) $input['code'] !== ''
            ? $this->vendorCode((string) $input['code'])
            : $this->generatedCode($name);
        $now = $this->store->now();
        try {
            $id = $this->store->insert('marketplace_vendor', [
                'code' => $code,
                'name' => $name,
                'slug' => $this->slug($name, $code),
                'status' => 'pending',
                'commission_percent' => '10.00',
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'description' => $this->optionalText($input, 'description', 2000),
                'contact_name' => $this->optionalText($input, 'contact_name', 255),
                'phone' => $this->optionalText($input, 'phone', 64),
                'date_add' => $now,
                'date_upd' => $now,
            ]);
        } catch (PDOException $exception) {
            $this->unique($exception, 'A vendor with that email or code already exists.');
        }
        $this->audit(['role' => 'system', 'id' => 0], 'vendor.register', 'vendor:' . $id, 'pending');

        return $this->presentVendor($this->requireVendor($id));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function registerShopper(array $input): array
    {
        $email = $this->email((string) ($input['email'] ?? ''));
        $password = $this->password((string) ($input['password'] ?? ''));
        $now = $this->store->now();
        try {
            $id = $this->store->insert('marketplace_shopper', [
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $this->requiredText($input, 'first_name', 1, 128),
                'last_name' => $this->requiredText($input, 'last_name', 1, 128),
                'mangopay_user_id' => null,
                'date_add' => $now,
            ]);
        } catch (PDOException $exception) {
            $this->unique($exception, 'A customer with that email already exists.');
        }

        return $this->presentShopper($this->requireShopper($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function login(string $role, string $email, string $password): array
    {
        $email = $this->email($email);
        if ($role === 'admin') {
            if ($this->adminEmail === '' || $this->adminPasswordHash === '') {
                throw new PlatformException('admin_not_configured', 'API admin credentials are not configured.', 503);
            }
            $matches = hash_equals(strtolower($this->adminEmail), $email) && password_verify($password, $this->adminPasswordHash);
            if (!$matches) {
                throw new PlatformException('unauthorized', 'The email or password is incorrect.', 401);
            }

            return $this->issueToken('admin', 0);
        }

        if ($role === 'vendor') {
            $row = $this->store->one('SELECT * FROM ' . $this->t('marketplace_vendor') . ' WHERE email = :email', ['email' => $email]);
            if ($row === null) {
                password_verify($password, $this->dummyHash());
                throw new PlatformException('unauthorized', 'The email or password is incorrect.', 401);
            }
            if (!password_verify($password, (string) $row['password_hash'])) {
                throw new PlatformException('unauthorized', 'The email or password is incorrect.', 401);
            }
            if ((string) $row['status'] !== 'active') {
                throw new PlatformException('vendor_not_active', 'This vendor account is not active.', 403);
            }

            return $this->issueToken('vendor', (int) $row['id_marketplace_vendor']);
        }

        if ($role === 'shopper') {
            $row = $this->store->one('SELECT * FROM ' . $this->t('marketplace_shopper') . ' WHERE email = :email', ['email' => $email]);
            if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
                if ($row === null) {
                    password_verify($password, $this->dummyHash());
                }
                throw new PlatformException('unauthorized', 'The email or password is incorrect.', 401);
            }

            return $this->issueToken('shopper', (int) $row['id_marketplace_shopper']);
        }

        throw new PlatformException('validation_error', 'Role must be admin, vendor or shopper.', 422);
    }

    /**
     * @return array{role: string, id: int, token_hash: string}
     */
    public function authenticate(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new PlatformException('unauthorized', 'Authentication is required.', 401);
        }
        $hash = hash('sha256', $token);
        $row = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_api_credential') . ' WHERE token_hash = :hash',
            ['hash' => $hash]
        );
        if ($row === null || (int) $row['expires_at'] < time()) {
            throw new PlatformException('unauthorized', 'Authentication is required.', 401);
        }
        if ((string) $row['role'] === 'vendor') {
            $vendor = $this->store->one(
                'SELECT status FROM ' . $this->t('marketplace_vendor') . ' WHERE id_marketplace_vendor = :id',
                ['id' => (int) $row['subject_id']]
            );
            if ($vendor === null || (string) $vendor['status'] !== 'active') {
                throw new PlatformException('vendor_not_active', 'This vendor account is not active.', 403);
            }
        }

        return ['role' => (string) $row['role'], 'id' => (int) $row['subject_id'], 'token_hash' => $hash];
    }

    public function revokeToken(string $tokenHash): void
    {
        $this->store->exec(
            'DELETE FROM ' . $this->t('marketplace_api_credential') . ' WHERE token_hash = :hash',
            ['hash' => $tokenHash]
        );
    }

    public function grant(string $role, string $permission): void
    {
        $this->store->insertIgnore('marketplace_api_permission', ['role' => $role, 'permission' => $permission]);
    }

    public function allows(string $role, string $permission): bool
    {
        return $this->store->value(
            'SELECT COUNT(*) FROM ' . $this->t('marketplace_api_permission') . ' WHERE role = :role AND permission = :permission',
            ['role' => $role, 'permission' => $permission]
        ) === '1';
    }

    public function tooMany(string $tokenHash, int $now): bool
    {
        $count = (int) $this->store->value(
            'SELECT COUNT(*) FROM ' . $this->t('marketplace_api_hit') . ' WHERE token_hash = :hash AND created_at > :since',
            ['hash' => $tokenHash, 'since' => $now - 60]
        );

        return $count >= $this->rateLimit;
    }

    public function hit(string $tokenHash, int $now): void
    {
        $this->store->insert('marketplace_api_hit', ['token_hash' => $tokenHash, 'created_at' => $now]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recallIdempotency(string $actorKey, string $key): ?array
    {
        return $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_idempotency') . ' WHERE actor_key = :actor AND idempotency_key = :key',
            ['actor' => $actorKey, 'key' => $key]
        );
    }

    public function rememberIdempotency(string $actorKey, string $key, string $hash, int $status, string $json): void
    {
        try {
            $this->store->insert('marketplace_idempotency', [
                'actor_key' => $actorKey,
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'status_code' => $status,
                'response_json' => $json,
                'date_add' => $this->store->now(),
            ]);
        } catch (PDOException $exception) {
            $this->unique($exception, 'This idempotency key is already in use.');
        }
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function approveVendor(array $actor, int $vendorId): array
    {
        $this->assertAdmin($actor);
        $this->requireVendor($vendorId);
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_vendor') . ' SET status = :status, date_upd = :now WHERE id_marketplace_vendor = :id',
            ['status' => 'active', 'now' => $this->store->now(), 'id' => $vendorId]
        );
        $this->audit($actor, 'vendor.approve', 'vendor:' . $vendorId, 'active');

        return $this->presentVendor($this->requireVendor($vendorId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function suspendVendor(array $actor, int $vendorId): array
    {
        $this->assertAdmin($actor);
        $this->requireVendor($vendorId);
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_vendor') . ' SET status = :status, date_upd = :now WHERE id_marketplace_vendor = :id',
            ['status' => 'suspended', 'now' => $this->store->now(), 'id' => $vendorId]
        );
        $this->store->exec(
            'DELETE FROM ' . $this->t('marketplace_api_credential') . ' WHERE role = :role AND subject_id = :id',
            ['role' => 'vendor', 'id' => $vendorId]
        );
        $this->audit($actor, 'vendor.suspend', 'vendor:' . $vendorId, 'suspended');

        return $this->presentVendor($this->requireVendor($vendorId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function setCommission(array $actor, int $vendorId, string $percent): array
    {
        $this->assertAdmin($actor);
        $this->requireVendor($vendorId);
        $normalized = $this->discount($percent);
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_vendor') . ' SET commission_percent = :percent, date_upd = :now WHERE id_marketplace_vendor = :id',
            ['percent' => $normalized, 'now' => $this->store->now(), 'id' => $vendorId]
        );
        $this->audit($actor, 'vendor.commission', 'vendor:' . $vendorId, $normalized);

        return $this->presentVendor($this->requireVendor($vendorId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateVendor(array $actor, int $vendorId, array $input): array
    {
        $this->assertVendor($actor, $vendorId);
        $current = $this->requireVendor($vendorId);
        $name = array_key_exists('name', $input) ? $this->requiredText($input, 'name', 2, 255) : (string) $current['name'];
        $minEnabled = array_key_exists('min_order_enabled', $input) ? (int) (bool) $input['min_order_enabled'] : (int) ($current['min_order_enabled'] ?? 0);
        $minCents = array_key_exists('min_order_cents', $input) ? $this->cents($input['min_order_cents'], 'Minimum order') : (int) ($current['min_order_cents'] ?? 0);
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_vendor')
            . ' SET name = :name, description = :description, contact_name = :contact, phone = :phone, city = :city, brands = :brands, coverage = :coverage, min_order_enabled = :min_enabled, min_order_cents = :min_cents, date_upd = :now WHERE id_marketplace_vendor = :id',
            [
                'name' => $name,
                'description' => array_key_exists('description', $input) ? $this->optionalText($input, 'description', 2000) : $current['description'],
                'contact' => array_key_exists('contact_name', $input) ? $this->optionalText($input, 'contact_name', 255) : $current['contact_name'],
                'phone' => array_key_exists('phone', $input) ? $this->optionalText($input, 'phone', 64) : $current['phone'],
                'city' => array_key_exists('city', $input) ? $this->optionalText($input, 'city', 128) : ($current['city'] ?? null),
                'brands' => array_key_exists('brands', $input) ? $this->optionalText($input, 'brands', 255) : ($current['brands'] ?? null),
                'coverage' => array_key_exists('coverage', $input) ? $this->optionalText($input, 'coverage', 255) : ($current['coverage'] ?? null),
                'min_enabled' => $minEnabled,
                'min_cents' => $minCents,
                'now' => $this->store->now(),
                'id' => $vendorId,
            ]
        );
        $this->bumpCatalog();

        return $this->presentVendor($this->requireVendor($vendorId));
    }

    /**
     * @return array<string, mixed>
     */
    public function vendorProfile(array $actor, int $vendorId): array
    {
        $this->assertVendor($actor, $vendorId);

        return $this->presentVendor($this->requireVendor($vendorId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function listVendors(array $actor, int $page, int $perPage): array
    {
        $this->assertAdmin($actor);
        [$page, $perPage, $offset] = $this->pageBounds($page, $perPage);
        $total = (int) $this->store->value('SELECT COUNT(*) FROM ' . $this->t('marketplace_vendor'));
        $rows = $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_vendor') . ' ORDER BY id_marketplace_vendor LIMIT ' . $perPage . ' OFFSET ' . $offset
        );

        return ['data' => array_map($this->presentVendor(...), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveShippingRule(array $actor, int $vendorId, array $input): array
    {
        $this->assertVendor($actor, $vendorId);
        $this->requireVendor($vendorId);
        $prefixes = $input['postcode_prefixes'] ?? [];
        if (!is_array($prefixes)) {
            throw new PlatformException('validation_error', 'Postcode prefixes must be a list.', 422);
        }
        $clean = [];
        foreach ($prefixes as $prefix) {
            $prefix = strtoupper(preg_replace('/\s+/', '', (string) $prefix) ?? '');
            if ($prefix === '') {
                continue;
            }
            if (!preg_match('/^[A-Z0-9]{1,8}$/', $prefix)) {
                throw new PlatformException('validation_error', 'A postcode prefix is invalid.', 422);
            }
            $clean[] = $prefix;
        }
        $base = $this->cents($input['base_cents'] ?? 0, 'Base shipping');
        $surcharge = $this->cents($input['surcharge_cents_per_kg'] ?? 0, 'Weight surcharge');
        $threshold = $input['free_threshold_cents'] ?? null;
        $threshold = $threshold === null || $threshold === '' ? null : $this->cents($threshold, 'Free-shipping threshold');
        $name = $this->requiredText($input, 'name', 2, 128);
        $palletBase = array_key_exists('pallet_base_cents', $input) ? $this->cents($input['pallet_base_cents'], 'Pallet freight') : 0;
        $palletFree = $input['pallet_free_threshold_cents'] ?? null;
        $palletFree = $palletFree === null || $palletFree === '' ? null : $this->cents($palletFree, 'Pallet free-shipping threshold');
        $parcelMaxWeight = $this->optionalPositive($input, 'parcel_max_weight_grams');
        $parcelMaxVolume = $this->optionalPositive($input, 'parcel_max_volume_cm3');
        $palletWeight = $this->optionalPositive($input, 'pallet_capacity_weight_grams');
        $palletVolume = $this->optionalPositive($input, 'pallet_capacity_volume_cm3');
        $now = $this->store->now();
        $existing = $this->store->one(
            'SELECT id_marketplace_shipping_rule FROM ' . $this->t('marketplace_shipping_rule') . ' WHERE id_marketplace_vendor = :vendor AND name = :name',
            ['vendor' => $vendorId, 'name' => $name]
        );
        $values = [
            'base_cents' => $base,
            'free_threshold_cents' => $threshold,
            'surcharge_cents_per_kg' => $surcharge,
            'postcode_prefixes' => implode(',', $clean),
            'pallet_base_cents' => $palletBase,
            'pallet_free_threshold_cents' => $palletFree,
            'parcel_max_weight_grams' => $parcelMaxWeight,
            'parcel_max_volume_cm3' => $parcelMaxVolume,
            'pallet_capacity_weight_grams' => $palletWeight,
            'pallet_capacity_volume_cm3' => $palletVolume,
            'date_upd' => $now,
        ];
        if ($existing !== null) {
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_shipping_rule')
                . ' SET base_cents = :base_cents, free_threshold_cents = :free_threshold_cents, surcharge_cents_per_kg = :surcharge_cents_per_kg, postcode_prefixes = :postcode_prefixes, pallet_base_cents = :pallet_base_cents, pallet_free_threshold_cents = :pallet_free_threshold_cents, parcel_max_weight_grams = :parcel_max_weight_grams, parcel_max_volume_cm3 = :parcel_max_volume_cm3, pallet_capacity_weight_grams = :pallet_capacity_weight_grams, pallet_capacity_volume_cm3 = :pallet_capacity_volume_cm3, date_upd = :date_upd WHERE id_marketplace_shipping_rule = :id',
                $values + ['id' => (int) $existing['id_marketplace_shipping_rule']]
            );
            $id = (int) $existing['id_marketplace_shipping_rule'];
        } else {
            $id = $this->store->insert('marketplace_shipping_rule', $values + [
                'id_marketplace_vendor' => $vendorId,
                'name' => $name,
                'active' => 1,
                'date_add' => $now,
            ]);
        }

        $this->bumpCatalog();

        return [
            'id' => $id,
            'vendor_id' => $vendorId,
            'name' => $name,
            'base_cents' => $base,
            'free_threshold_cents' => $threshold,
            'surcharge_cents_per_kg' => $surcharge,
            'postcode_prefixes' => $clean,
            'pallet_base_cents' => $palletBase,
            'pallet_free_threshold_cents' => $palletFree,
            'parcel_max_weight_grams' => $parcelMaxWeight,
            'parcel_max_volume_cm3' => $parcelMaxVolume,
            'pallet_capacity_weight_grams' => $palletWeight,
            'pallet_capacity_volume_cm3' => $palletVolume,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function quote(int $vendorId, string $postcode, int $productCents, int $weightGrams, int $volumeCm3 = 0, bool $palletOnly = false): array
    {
        $postcode = $this->postcode($postcode);
        if ($productCents < 0 || $weightGrams < 0 || $volumeCm3 < 0) {
            throw new PlatformException('validation_error', 'Shipping inputs cannot be negative.', 422);
        }
        $rules = $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_shipping_rule') . ' WHERE id_marketplace_vendor = :id AND active = 1 ORDER BY id_marketplace_shipping_rule',
            ['id' => $vendorId]
        );
        $chosen = (new ShippingRuleSelector())->choose($rules, $postcode);
        if ($chosen === null) {
            return ['eligible' => false, 'amount_cents' => 0, 'free_shipping' => false, 'reason' => 'This vendor has no shipping rule.'];
        }
        $prefixes = $this->prefixes((string) $chosen['postcode_prefixes']);
        $vendor = $this->requireVendor($vendorId);
        $quote = (new ShippingCalculator())->quote(
            $this->shippingRuleFromRow($vendorId, $chosen, $prefixes, $vendor),
            $productCents,
            $weightGrams,
            $postcode,
            $volumeCm3,
            $palletOnly,
        );

        return [
            'eligible' => $quote->eligible,
            'amount_cents' => $quote->amountCents,
            'free_shipping' => $quote->freeShippingApplied,
            'reason' => $quote->reason,
            'rule_name' => (string) $chosen['name'],
        ];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function upsertOffer(array $actor, int $vendorId, array $input): array
    {
        $this->assertVendor($actor, $vendorId);
        $vendor = $this->requireVendor($vendorId);
        if ((string) $vendor['status'] !== 'active' && $actor['role'] !== 'admin') {
            throw new PlatformException('vendor_not_active', 'This vendor account is not active.', 403);
        }
        $sku = $this->sku((string) ($input['sku'] ?? ''));
        $price = $this->cents($input['price_cents'] ?? -1, 'Price');
        $stock = $this->quantity($input['stock'] ?? 0);
        $weight = $this->quantity($input['weight_grams'] ?? 0);
        $discount = $this->discount((string) ($input['discount'] ?? '0'));
        $ean = preg_replace('/\s+/', '', (string) ($input['ean'] ?? '')) ?? '';
        if ($ean !== '' && !preg_match('/^\d{8,14}$/', $ean)) {
            throw new PlatformException('validation_error', 'EAN must be 8 to 14 digits.', 422);
        }
        $productId = (int) ($input['product_id'] ?? 0);
        $title = $this->requiredText($input, 'title', 1, 255);
        $now = $this->store->now();
        $existing = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_vendor_offer') . ' WHERE id_marketplace_vendor = :vendor AND vendor_sku = :sku',
            ['vendor' => $vendorId, 'sku' => $sku]
        );
        if ($productId < 1) {
            $productId = $existing !== null ? (int) $existing['id_product'] : $this->matchOrCreateProduct($vendorId, $sku, $ean, $title);
        }
        $values = [
            'id_product' => $productId,
            'ean13' => $ean === '' ? null : $ean,
            'brand' => $this->optionalText($input, 'brand', 128),
            'title' => $title,
            'description' => $this->optionalText($input, 'description', 5000),
            'price_cents' => $price,
            'discount_percent' => $discount,
            'weight_grams' => $weight,
            'volume_cm3' => $this->quantity($input['volume_cm3'] ?? 0),
            'pallet_only' => !empty($input['pallet_only']) ? 1 : 0,
            'image_url' => $this->imageUrl((string) ($input['image_url'] ?? '')),
            'date_upd' => $now,
        ];
        if ($existing !== null) {
            $offerId = (int) $existing['id_marketplace_vendor_offer'];
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_vendor_offer')
                . ' SET id_product = :id_product, ean13 = :ean13, brand = :brand, title = :title, description = :description, price_cents = :price_cents, discount_percent = :discount_percent, weight_grams = :weight_grams, volume_cm3 = :volume_cm3, pallet_only = :pallet_only, image_url = :image_url, date_upd = :date_upd WHERE id_marketplace_vendor_offer = :id',
                $values + ['id' => $offerId]
            );
            $this->setStockQuantity($offerId, $stock);
            $action = 'updated';
        } else {
            $offerId = $this->store->insert('marketplace_vendor_offer', $values + [
                'id_marketplace_vendor' => $vendorId,
                'vendor_sku' => $sku,
                'active' => 1,
                'date_add' => $now,
            ]);
            $this->store->insert('marketplace_vendor_stock', [
                'id_marketplace_vendor_offer' => $offerId,
                'quantity' => $stock,
                'reserved_quantity' => 0,
                'low_stock_threshold' => 3,
                'date_upd' => $now,
            ]);
            $action = 'created';
        }
        $this->bumpCatalog();

        return $this->presentOffer($this->requireOffer($offerId)) + ['action' => $action];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listOffers(array $filters, int $page, int $perPage, string $sort, string $direction): array
    {
        [$page, $perPage, $offset] = $this->pageBounds($page, $perPage);
        $sorts = [
            'price_cents' => 'o.price_cents',
            'sku' => 'o.vendor_sku',
            'id' => 'o.id_marketplace_vendor_offer',
        ];
        if (!isset($sorts[$sort])) {
            throw new PlatformException('validation_error', 'That sort field is not available.', 422);
        }
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $where = ['o.active = 1'];
        $params = [];
        if (isset($filters['vendor_id'])) {
            $where[] = 'o.id_marketplace_vendor = :vendor';
            $params['vendor'] = (int) $filters['vendor_id'];
        }
        if (isset($filters['vendor_ids']) && is_array($filters['vendor_ids'])) {
            $ids = [];
            foreach ($filters['vendor_ids'] as $vendorId) {
                $vendorId = (int) $vendorId;
                if ($vendorId > 0) {
                    $ids[$vendorId] = $vendorId;
                }
            }
            if (count($ids) > 20) {
                throw new PlatformException('validation_error', 'Choose at most 20 vendors.', 422);
            }
            if ($ids !== []) {
                $holders = [];
                $index = 0;
                foreach ($ids as $vendorId) {
                    $key = 'vendors_' . $index;
                    $holders[] = ':' . $key;
                    $params[$key] = $vendorId;
                    $index++;
                }
                $where[] = 'o.id_marketplace_vendor IN (' . implode(', ', $holders) . ')';
            }
        }
        if (isset($filters['product_id'])) {
            $where[] = 'o.id_product = :product';
            $params['product'] = (int) $filters['product_id'];
        }
        if (isset($filters['ean']) && (string) $filters['ean'] !== '') {
            $where[] = 'o.ean13 = :ean';
            $params['ean'] = (string) $filters['ean'];
        }
        $sqlWhere = ' WHERE ' . implode(' AND ', $where);
        $cache = RedisClient::fromEnvironment();
        $cacheKey = '';
        if ($cache instanceof RedisClient) {
            $version = $cache->get('mp:catalog:version') ?? '0';
            $cacheKey = 'mp:offers:' . $version . ':' . hash('sha256', (string) json_encode([$where, $params, $page, $perPage, $sort, $direction]));
            $cached = $cache->get($cacheKey);
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && isset($decoded['data'], $decoded['total'], $decoded['page'], $decoded['per_page'])) {
                    return $decoded;
                }
            }
        }
        $total = (int) $this->store->value('SELECT COUNT(*) FROM ' . $this->t('marketplace_vendor_offer') . ' o' . $sqlWhere, $params);
        $rows = $this->store->all(
            'SELECT o.*, s.quantity, s.reserved_quantity FROM ' . $this->t('marketplace_vendor_offer') . ' o LEFT JOIN ' . $this->t('marketplace_vendor_stock') . ' s ON s.id_marketplace_vendor_offer = o.id_marketplace_vendor_offer'
            . $sqlWhere . ' ORDER BY ' . $sorts[$sort] . ' ' . $direction . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        $result = [
            'data' => array_map(fn (array $row): array => $this->presentOffer($row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
        if ($cache instanceof RedisClient && $cacheKey !== '') {
            $cache->set($cacheKey, (string) json_encode($result), 30);
        }

        return $result;
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listProducts(int $page, int $perPage): array
    {
        [$page, $perPage, $offset] = $this->pageBounds($page, $perPage);
        $total = (int) $this->store->value('SELECT COUNT(DISTINCT id_product) FROM ' . $this->t('marketplace_vendor_offer') . ' WHERE active = 1');
        $rows = $this->store->all(
            'SELECT id_product, MIN(price_cents) AS best_price_cents, COUNT(*) AS offer_count FROM ' . $this->t('marketplace_vendor_offer')
            . ' WHERE active = 1 GROUP BY id_product ORDER BY best_price_cents ASC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $data = [];
        foreach ($rows as $row) {
            $sample = $this->store->one(
                'SELECT title, ean13, vendor_sku FROM ' . $this->t('marketplace_vendor_offer') . ' WHERE id_product = :id AND active = 1 ORDER BY price_cents ASC',
                ['id' => (int) $row['id_product']]
            );
            $data[] = [
                'product_id' => (int) $row['id_product'],
                'title' => (string) ($sample['title'] ?? ''),
                'sku' => (string) ($sample['vendor_sku'] ?? ''),
                'ean' => (string) ($sample['ean13'] ?? ''),
                'best_price_cents' => (int) $row['best_price_cents'],
                'offer_count' => (int) $row['offer_count'],
            ];
        }

        return ['data' => $data, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function setStock(array $actor, int $vendorId, int $offerId, int $quantity): array
    {
        $this->assertVendor($actor, $vendorId);
        $offer = $this->requireOffer($offerId);
        if ((int) $offer['id_marketplace_vendor'] !== $vendorId) {
            throw new PlatformException('not_found', 'The offer was not found.', 404);
        }
        $this->setStockQuantity($offerId, $this->quantity($quantity));
        $this->bumpCatalog();

        return $this->presentOffer($this->requireOffer($offerId));
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function optimizeCart(string $postcode, array $lines): array
    {
        $postcode = $this->postcode($postcode);
        $lines = $this->cartLines($lines);
        $result = $this->runOptimizer($postcode, $lines);

        return $this->presentOptimization($result);
    }

    /**
     * @param array{role: string, id: int} $actor
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function checkout(array $actor, string $postcode, array $lines): array
    {
        $this->assertShopper($actor);
        $postcode = $this->postcode($postcode);
        $lines = $this->cartLines($lines);

        return $this->store->transaction(function () use ($actor, $postcode, $lines): array {
            $this->releaseExpired(time());
            $result = $this->runOptimizer($postcode, $lines);
            if ($result->status === OptimizationStatus::Infeasible || $result->vendors === []) {
                throw new PlatformException('infeasible', $result->explanation, 409);
            }
            $now = $this->store->now();
            $reference = $this->reference();
            $orderId = $this->store->insert('marketplace_order', [
                'reference' => $reference,
                'id_marketplace_cart' => null,
                'postcode' => $postcode,
                'product_cents' => $result->productSubtotalCents,
                'shipping_cents' => $result->shippingTotalCents,
                'grand_cents' => $result->grandTotalCents,
                'optimization_status' => $result->status->value,
                'optimization_note' => $result->explanation,
                'status' => 'reserved',
                'id_customer' => null,
                'payment_method' => null,
                'payment_status' => 'unpaid',
                'id_marketplace_shopper' => (int) $actor['id'],
                'date_add' => $now,
            ]);
            foreach ($result->vendors as $vendor) {
                $vendorOrderId = $this->store->insert('marketplace_vendor_order', [
                    'id_marketplace_order' => $orderId,
                    'reference' => $reference . '-' . $vendor->vendorCode,
                    'parent_reference' => $reference,
                    'id_marketplace_vendor' => $vendor->vendorId,
                    'product_cents' => $vendor->productSubtotalCents,
                    'shipping_cents' => $vendor->shippingCents,
                    'grand_cents' => $vendor->grandTotalCents(),
                    'postcode' => $postcode,
                    'optimization_status' => $result->status->value,
                    'status' => 'reserved',
                    'id_order' => null,
                    'shop_reference' => null,
                    'fulfillment_status' => 'pending',
                    'date_add' => $now,
                ]);
                foreach ($vendor->lines as $line) {
                    $this->store->insert('marketplace_vendor_order_line', [
                        'id_marketplace_vendor_order' => $vendorOrderId,
                        'id_marketplace_vendor_offer' => $line->offerId,
                        'id_product' => $line->productId,
                        'product_name' => substr($line->productName, 0, 255),
                        'quantity' => $line->quantity,
                        'unit_price_cents' => $line->unitPriceCents,
                        'line_total_cents' => $line->lineTotalCents,
                    ]);
                    $this->reserve($line->offerId, $vendorOrderId, $line->quantity);
                }
            }
            $this->event($orderId, 'reserved', $result->status->value);
            $order = $this->requireOrder($orderId);

            return $this->presentOrder($order) + ['optimization' => $this->presentOptimization($result)];
        }, true);
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function cancelOrder(array $actor, int $orderId): array
    {
        $order = $this->requireOrder($orderId);
        $this->assertOrderShopper($actor, $order);
        if (in_array((string) $order['payment_status'], ['paid', 'partially_refunded', 'refunded'], true)) {
            throw new PlatformException('conflict', 'A paid order must be refunded instead of cancelled.', 409);
        }
        if ((string) $order['status'] === 'cancelled') {
            return $this->presentOrder($order);
        }
        $this->store->transaction(function () use ($order, $actor): void {
            foreach ($this->vendorOrders((int) $order['id_marketplace_order']) as $vendorOrder) {
                $this->releaseReservations((int) $vendorOrder['id_marketplace_vendor_order']);
                $this->store->exec(
                    'UPDATE ' . $this->t('marketplace_vendor_order') . ' SET status = :status WHERE id_marketplace_vendor_order = :id',
                    ['status' => 'cancelled', 'id' => (int) $vendorOrder['id_marketplace_vendor_order']]
                );
            }
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_order') . ' SET status = :status, payment_status = :payment WHERE id_marketplace_order = :id',
                ['status' => 'cancelled', 'payment' => 'cancelled', 'id' => (int) $order['id_marketplace_order']]
            );
            $this->event((int) $order['id_marketplace_order'], 'cancelled', 'Cancelled by ' . $actor['role']);
            $this->audit($actor, 'order.cancel', 'order:' . $order['id_marketplace_order'], 'cancelled');
        });

        return $this->presentOrder($this->requireOrder($orderId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function fulfill(array $actor, int $vendorOrderId): array
    {
        $vendorOrder = $this->requireVendorOrder($vendorOrderId);
        $this->assertVendor($actor, (int) $vendorOrder['id_marketplace_vendor']);
        $order = $this->requireOrder((int) $vendorOrder['id_marketplace_order']);
        if (!in_array((string) $order['payment_status'], ['paid', 'partially_refunded'], true)) {
            throw new PlatformException('conflict', 'Unpaid orders cannot be fulfilled.', 409);
        }
        if ((string) $vendorOrder['fulfillment_status'] === 'fulfilled') {
            return $this->presentVendorOrder($vendorOrder);
        }
        $this->store->transaction(function () use ($vendorOrder, $actor): void {
            foreach ($this->orderLines((int) $vendorOrder['id_marketplace_vendor_order']) as $line) {
                $quantity = (int) $line['quantity'];
                $changed = $this->store->exec(
                    'UPDATE ' . $this->t('marketplace_vendor_stock')
                    . ' SET quantity = quantity - :quantity, reserved_quantity = reserved_quantity - :reserved, date_upd = :now'
                    . ' WHERE id_marketplace_vendor_offer = :offer AND quantity >= :have AND reserved_quantity >= :held',
                    [
                        'quantity' => $quantity,
                        'reserved' => $quantity,
                        'now' => $this->store->now(),
                        'offer' => (int) $line['id_marketplace_vendor_offer'],
                        'have' => $quantity,
                        'held' => $quantity,
                    ]
                );
                if ($changed !== 1) {
                    throw new PlatformException('conflict', 'Stock could not be fulfilled.', 409);
                }
                $this->store->exec(
                    'UPDATE ' . $this->t('marketplace_stock_reservation') . ' SET status = :consumed WHERE id_marketplace_vendor_order = :order_id AND id_marketplace_vendor_offer = :offer AND status = :active',
                    [
                        'consumed' => 'consumed',
                        'order_id' => (int) $vendorOrder['id_marketplace_vendor_order'],
                        'offer' => (int) $line['id_marketplace_vendor_offer'],
                        'active' => 'active',
                    ]
                );
            }
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_vendor_order') . ' SET fulfillment_status = :fulfilled, status = :status WHERE id_marketplace_vendor_order = :id',
                ['fulfilled' => 'fulfilled', 'status' => 'fulfilled', 'id' => (int) $vendorOrder['id_marketplace_vendor_order']]
            );
            $this->audit($actor, 'order.fulfill', 'vendor_order:' . $vendorOrder['id_marketplace_vendor_order'], 'fulfilled');
        });
        $pending = (int) $this->store->value(
            'SELECT COUNT(*) FROM ' . $this->t('marketplace_vendor_order') . ' WHERE id_marketplace_order = :id AND fulfillment_status <> :fulfilled',
            ['id' => (int) $vendorOrder['id_marketplace_order'], 'fulfilled' => 'fulfilled']
        );
        if ($pending === 0) {
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_order') . ' SET status = :status WHERE id_marketplace_order = :id',
                ['status' => 'fulfilled', 'id' => (int) $vendorOrder['id_marketplace_order']]
            );
            $this->event((int) $vendorOrder['id_marketplace_order'], 'fulfilled', 'All vendor shipments fulfilled.');
        }

        return $this->presentVendorOrder($this->requireVendorOrder($vendorOrderId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function orderFor(array $actor, int $orderId): array
    {
        $order = $this->requireOrder($orderId);
        if ($actor['role'] === 'admin') {
            return $this->presentOrder($order);
        }
        $this->assertOrderShopper($actor, $order);

        return $this->presentOrder($order);
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listOrders(array $actor, int $page, int $perPage): array
    {
        [$page, $perPage, $offset] = $this->pageBounds($page, $perPage);
        if ($actor['role'] === 'admin') {
            $where = '';
            $params = [];
        } elseif ($actor['role'] === 'shopper') {
            $where = ' WHERE id_marketplace_shopper = :id';
            $params = ['id' => (int) $actor['id']];
        } else {
            throw new PlatformException('forbidden', 'Orders are not available for this account.', 403);
        }
        $total = (int) $this->store->value('SELECT COUNT(*) FROM ' . $this->t('marketplace_order') . $where, $params);
        $rows = $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_order') . $where . ' ORDER BY id_marketplace_order DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'data' => array_map(fn (array $row): array => $this->presentOrder($row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listVendorOrders(array $actor, int $vendorId, int $page, int $perPage): array
    {
        $this->assertVendor($actor, $vendorId);
        [$page, $perPage, $offset] = $this->pageBounds($page, $perPage);
        $params = ['id' => $vendorId];
        $total = (int) $this->store->value(
            'SELECT COUNT(*) FROM ' . $this->t('marketplace_vendor_order') . ' WHERE id_marketplace_vendor = :id',
            $params
        );
        $rows = $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_vendor_order') . ' WHERE id_marketplace_vendor = :id ORDER BY id_marketplace_vendor_order DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'data' => array_map(fn (array $row): array => $this->presentVendorOrder($row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function importCsv(array $actor, int $vendorId, string $filename, string $csv): array
    {
        $this->assertVendor($actor, $vendorId);
        if (strlen($csv) > 2000000) {
            throw new PlatformException('payload_too_large', 'CSV files are limited to 2 MB.', 413);
        }
        $vendor = $this->requireVendor($vendorId);
        $filename = $this->filename($filename);
        $now = $this->store->now();
        try {
            $rows = (new CsvOfferParser())->parse($csv);
        } catch (\InvalidArgumentException $exception) {
            $jobId = $this->store->insert('marketplace_import_job', [
                'id_marketplace_vendor' => $vendorId,
                'filename' => $filename,
                'status' => 'failed',
                'rows_total' => 0,
                'rows_created' => 0,
                'rows_updated' => 0,
                'rows_failed' => 1,
                'error_log' => $exception->getMessage(),
                'date_add' => $now,
                'date_upd' => $now,
            ]);

            return $this->presentJob($this->requireJob($jobId));
        }
        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];
        foreach ($rows as $row) {
            if ($row->vendorCode !== (string) $vendor['code']) {
                ++$failed;
                $errors[] = 'Line ' . $row->lineNumber . ' is for a different vendor.';
                continue;
            }
            try {
                $action = $this->applyImportRow($vendor, $row);
                $action === 'created' ? ++$created : ++$updated;
            } catch (PlatformException $exception) {
                ++$failed;
                $errors[] = 'Line ' . $row->lineNumber . ': ' . $exception->getMessage();
            }
        }
        $jobId = $this->store->insert('marketplace_import_job', [
            'id_marketplace_vendor' => $vendorId,
            'filename' => $filename,
            'status' => $created + $updated > 0 || $failed === 0 ? 'completed' : 'failed',
            'rows_total' => count($rows),
            'rows_created' => $created,
            'rows_updated' => $updated,
            'rows_failed' => $failed,
            'error_log' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR),
            'date_add' => $now,
            'date_upd' => $now,
        ]);
        $this->audit($actor, 'import.completed', 'import:' . $jobId, $created . '/' . $updated . '/' . $failed);

        return $this->presentJob($this->requireJob($jobId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return list<array<string, mixed>>
     */
    public function listImports(array $actor, int $vendorId): array
    {
        $this->assertVendor($actor, $vendorId);

        return array_map(
            fn (array $row): array => $this->presentJob($row),
            $this->store->all(
                'SELECT * FROM ' . $this->t('marketplace_import_job') . ' WHERE id_marketplace_vendor = :id ORDER BY id_marketplace_import_job DESC LIMIT 100',
                ['id' => $vendorId]
            )
        );
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function scheduleImport(array $actor, int $vendorId, string $filename, string $csv, int $intervalMinutes): array
    {
        $this->assertVendor($actor, $vendorId);
        if ($intervalMinutes < 5 || $intervalMinutes > 10080) {
            throw new PlatformException('validation_error', 'Import interval must be between 5 and 10080 minutes.', 422);
        }
        if (strlen($csv) > 2000000) {
            throw new PlatformException('payload_too_large', 'CSV files are limited to 2 MB.', 413);
        }
        $id = $this->store->insert('marketplace_import_schedule', [
            'id_marketplace_vendor' => $vendorId,
            'filename' => $this->filename($filename),
            'csv_body' => $csv,
            'interval_minutes' => $intervalMinutes,
            'last_run' => null,
            'active' => 1,
            'date_add' => $this->store->now(),
        ]);

        return ['id' => $id, 'vendor_id' => $vendorId, 'interval_minutes' => $intervalMinutes, 'active' => true];
    }

    /**
     * @return array{released_reservations: int, imports: list<array<string, mixed>>}
     */
    public function runMaintenance(int $now): array
    {
        $this->store->exec(
            'DELETE FROM ' . $this->t('marketplace_api_hit') . ' WHERE created_at < :cutoff',
            ['cutoff' => $now - 86400]
        );
        $this->store->exec(
            'DELETE FROM ' . $this->t('marketplace_audit_log') . ' WHERE date_add < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', $now - 365 * 86400)]
        );
        $released = $this->releaseExpired($now);
        $imports = [];
        $schedules = $this->store->all('SELECT * FROM ' . $this->t('marketplace_import_schedule') . ' WHERE active = 1');
        foreach ($schedules as $schedule) {
            $last = $schedule['last_run'];
            $due = $last === null || $last === '' || (int) $last + ((int) $schedule['interval_minutes'] * 60) <= $now;
            if (!$due) {
                continue;
            }
            $vendorId = (int) $schedule['id_marketplace_vendor'];
            $vendor = $this->requireVendor($vendorId);
            if ((string) $vendor['status'] !== 'active') {
                $this->touchSchedule((int) $schedule['id_marketplace_import_schedule'], $now);
                continue;
            }
            $imports[] = $this->importCsv(
                ['role' => 'vendor', 'id' => $vendorId],
                $vendorId,
                (string) $schedule['filename'],
                (string) $schedule['csv_body'],
            );
            $this->touchSchedule((int) $schedule['id_marketplace_import_schedule'], $now);
        }

        return ['released_reservations' => $released, 'imports' => $imports];
    }

    public function releaseExpired(int $now): int
    {
        return (int) $this->store->transaction(function () use ($now): int {
            $rows = $this->store->all(
                'SELECT r.* FROM ' . $this->t('marketplace_stock_reservation') . ' r
                 INNER JOIN ' . $this->t('marketplace_vendor_order') . ' vo ON vo.id_marketplace_vendor_order = r.id_marketplace_vendor_order
                 INNER JOIN ' . $this->t('marketplace_order') . ' o ON o.id_marketplace_order = vo.id_marketplace_order
                 WHERE r.status = :status AND r.expires_at < :now
                 AND o.payment_status NOT IN (\'paid\', \'partially_refunded\', \'refunded\')',
                ['status' => 'active', 'now' => date('Y-m-d H:i:s', $now)]
            );
            $count = 0;
            foreach ($rows as $row) {
                $changed = $this->store->exec(
                    'UPDATE ' . $this->t('marketplace_stock_reservation') . ' SET status = :released WHERE id_marketplace_stock_reservation = :id AND status = :active',
                    ['released' => 'released', 'id' => (int) $row['id_marketplace_stock_reservation'], 'active' => 'active']
                );
                if ($changed !== 1) {
                    continue;
                }
                $stock = $this->store->exec(
                    'UPDATE ' . $this->t('marketplace_vendor_stock') . ' SET reserved_quantity = reserved_quantity - :quantity, date_upd = :now WHERE id_marketplace_vendor_offer = :offer AND reserved_quantity >= :need',
                    [
                        'quantity' => (int) $row['quantity'],
                        'now' => $this->store->now(),
                        'offer' => (int) $row['id_marketplace_vendor_offer'],
                        'need' => (int) $row['quantity'],
                    ]
                );
                if ($stock !== 1) {
                    throw new PlatformException('conflict', 'Reserved stock could not be released.', 409);
                }
                ++$count;
            }

            return $count;
        });
    }

    /**
     * @param array{role: string, id: int} $actor
     */
    public function exportOffers(array $actor, int $vendorId): string
    {
        $this->assertVendor($actor, $vendorId);
        $vendor = $this->requireVendor($vendorId);
        $rows = $this->store->all(
            'SELECT o.*, s.quantity FROM ' . $this->t('marketplace_vendor_offer') . ' o INNER JOIN ' . $this->t('marketplace_vendor_stock') . ' s ON s.id_marketplace_vendor_offer = o.id_marketplace_vendor_offer WHERE o.id_marketplace_vendor = :id ORDER BY o.vendor_sku',
            ['id' => $vendorId]
        );
        $csv = [CsvOfferParser::HEADER];
        foreach ($rows as $row) {
            $csv[] = [
                $this->csvCell((string) $vendor['code']),
                $this->csvCell((string) $row['vendor_sku']),
                $this->csvCell((string) ($row['ean13'] ?? '')),
                $this->csvCell((string) ($row['brand'] ?? '')),
                $this->csvCell((string) ($row['title'] ?? '')),
                $this->csvCell((string) ($row['description'] ?? '')),
                Money::format((int) $row['price_cents']),
                $this->percent($row['discount_percent']),
                (string) (int) $row['quantity'],
                $this->kilograms((int) $row['weight_grams']),
                $this->csvCell((string) ($row['image_url'] ?? '')),
            ];
        }

        return $this->csv($csv);
    }

    /**
     * @param array{role: string, id: int} $actor
     */
    public function exportOrders(array $actor, int $vendorId): string
    {
        $this->assertVendor($actor, $vendorId);
        $rows = $this->store->all(
            'SELECT vo.reference AS vendor_reference, vo.parent_reference, vo.status, o.payment_status, l.product_name, l.quantity, l.unit_price_cents, l.line_total_cents, vo.date_add
             FROM ' . $this->t('marketplace_vendor_order') . ' vo
             INNER JOIN ' . $this->t('marketplace_order') . ' o ON o.id_marketplace_order = vo.id_marketplace_order
             INNER JOIN ' . $this->t('marketplace_vendor_order_line') . ' l ON l.id_marketplace_vendor_order = vo.id_marketplace_vendor_order
             WHERE vo.id_marketplace_vendor = :id ORDER BY vo.id_marketplace_vendor_order',
            ['id' => $vendorId]
        );
        $csv = [['vendor_reference', 'parent_reference', 'status', 'payment_status', 'product_name', 'quantity', 'unit_price_cents', 'line_total_cents', 'date_add']];
        foreach ($rows as $row) {
            $csv[] = [
                $this->csvCell((string) $row['vendor_reference']),
                $this->csvCell((string) $row['parent_reference']),
                $this->csvCell((string) $row['status']),
                $this->csvCell((string) $row['payment_status']),
                $this->csvCell((string) $row['product_name']),
                $this->csvCell((string) $row['quantity']),
                $this->csvCell((string) $row['unit_price_cents']),
                $this->csvCell((string) $row['line_total_cents']),
                $this->csvCell((string) $row['date_add']),
            ];
        }

        return $this->csv($csv);
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function createCustomerInvoice(array $actor, int $vendorOrderId): array
    {
        $vendorOrder = $this->requireVendorOrder($vendorOrderId);
        $this->assertVendor($actor, (int) $vendorOrder['id_marketplace_vendor']);
        $order = $this->requireOrder((int) $vendorOrder['id_marketplace_order']);
        if (!in_array((string) $order['payment_status'], ['paid', 'partially_refunded', 'refunded'], true)) {
            throw new PlatformException('conflict', 'An invoice can be created after payment.', 409);
        }

        return $this->createInvoice('vendor', (int) $vendorOrder['id_marketplace_vendor'], (int) $order['id_marketplace_order'], $vendorOrderId, 'customer', (int) $vendorOrder['grand_cents'], 'VIN-');
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function createCommissionInvoice(array $actor, int $vendorOrderId): array
    {
        $this->assertAdmin($actor);
        $vendorOrder = $this->requireVendorOrder($vendorOrderId);
        $fee = (int) $this->store->value(
            'SELECT COALESCE(SUM(commission_cents), 0) FROM ' . $this->t('marketplace_commission') . ' WHERE id_marketplace_vendor_order = :id',
            ['id' => $vendorOrderId]
        );

        return $this->createInvoice('marketplace', (int) $vendorOrder['id_marketplace_vendor'], (int) $vendorOrder['id_marketplace_order'], $vendorOrderId, 'commission', max(0, $fee), 'CIN-');
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function issueInvoice(array $actor, int $invoiceId): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        $this->assertInvoiceAccess($actor, $invoice, true);
        if ((string) $invoice['status'] === 'pending') {
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_invoice') . ' SET status = :status, date_upd = :now WHERE id_marketplace_invoice = :id',
                ['status' => 'issued', 'now' => $this->store->now(), 'id' => $invoiceId]
            );
        }

        return $this->presentInvoice($this->requireInvoice($invoiceId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function uploadInvoice(array $actor, int $invoiceId, string $encoded): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        $this->assertInvoiceAccess($actor, $invoice, true);
        if ((string) $invoice['status'] === 'pending') {
            throw new PlatformException('conflict', 'Issue the invoice before uploading a PDF.', 409);
        }
        $binary = base64_decode($encoded, true);
        if (!is_string($binary) || $binary === '') {
            throw new PlatformException('validation_error', 'Invoice content must be base64.', 422);
        }
        if (strlen($binary) > 5000000) {
            throw new PlatformException('payload_too_large', 'Invoice PDFs are limited to 5 MB.', 413);
        }
        if (!str_starts_with($binary, '%PDF')) {
            throw new PlatformException('validation_error', 'Only PDF invoices can be uploaded.', 422);
        }
        $name = bin2hex(random_bytes(16)) . '.pdf';
        $path = $this->invoiceDirectory . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($path, $binary) === false) {
            throw new PlatformException('storage_unavailable', 'The invoice could not be stored.', 500);
        }
        chmod($path, 0600);
        $existing = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_invoice_file') . ' WHERE id_marketplace_invoice = :id',
            ['id' => $invoiceId]
        );
        if ($existing !== null) {
            $old = $this->invoiceDirectory . DIRECTORY_SEPARATOR . $existing['stored_name'];
            if (is_file($old)) {
                unlink($old);
            }
            $this->store->exec('DELETE FROM ' . $this->t('marketplace_invoice_file') . ' WHERE id_marketplace_invoice = :id', ['id' => $invoiceId]);
        }
        $this->store->insert('marketplace_invoice_file', [
            'id_marketplace_invoice' => $invoiceId,
            'stored_name' => $name,
            'sha256' => hash('sha256', $binary),
            'bytes' => strlen($binary),
            'mime' => 'application/pdf',
            'date_add' => $this->store->now(),
        ]);
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_invoice') . ' SET status = :status, date_upd = :now WHERE id_marketplace_invoice = :id',
            ['status' => 'uploaded', 'now' => $this->store->now(), 'id' => $invoiceId]
        );
        $this->audit($actor, 'invoice.upload', 'invoice:' . $invoiceId, hash('sha256', $binary));

        return $this->presentInvoice($this->requireInvoice($invoiceId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listInvoices(array $actor, int $page, int $perPage): array
    {
        [$page, $perPage, $offset] = $this->pageBounds($page, $perPage);
        if ($actor['role'] === 'admin') {
            $where = '';
            $params = [];
        } elseif ($actor['role'] === 'vendor') {
            $where = ' WHERE id_marketplace_vendor = :id';
            $params = ['id' => (int) $actor['id']];
        } elseif ($actor['role'] === 'shopper') {
            $where = ' WHERE kind IN (:customer_kind, :credit_kind) AND id_marketplace_order IN (SELECT id_marketplace_order FROM ' . $this->t('marketplace_order') . ' WHERE id_marketplace_shopper = :id)';
            $params = ['customer_kind' => 'customer', 'credit_kind' => 'credit_note', 'id' => (int) $actor['id']];
        } else {
            throw new PlatformException('forbidden', 'Invoices are not available for this account.', 403);
        }
        $total = (int) $this->store->value('SELECT COUNT(*) FROM ' . $this->t('marketplace_invoice') . $where, $params);
        $rows = $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_invoice') . $where . ' ORDER BY id_marketplace_invoice DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'data' => array_map(fn (array $row): array => $this->presentInvoice($row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function invoiceFor(array $actor, int $invoiceId): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        $this->assertInvoiceAccess($actor, $invoice, false);

        return $this->presentInvoice($invoice);
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array{filename: string, content_base64: string}
     */
    public function downloadInvoice(array $actor, int $invoiceId): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        $this->assertInvoiceAccess($actor, $invoice, false);
        if ((string) $invoice['status'] === 'pending') {
            throw new PlatformException('conflict', 'This invoice has not been issued.', 409);
        }
        $file = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_invoice_file') . ' WHERE id_marketplace_invoice = :id',
            ['id' => $invoiceId]
        );
        if ($file === null) {
            throw new PlatformException('not_found', 'This invoice has no uploaded file.', 404);
        }
        $name = (string) $file['stored_name'];
        if (!preg_match('/^[a-f0-9]{32}\.pdf$/', $name)) {
            throw new PlatformException('not_found', 'This invoice file is not available.', 404);
        }
        $root = realpath($this->invoiceDirectory);
        $path = realpath($this->invoiceDirectory . DIRECTORY_SEPARATOR . $name);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new PlatformException('not_found', 'This invoice file is not available.', 404);
        }

        return [
            'filename' => (string) $invoice['number'] . '.pdf',
            'content_base64' => base64_encode((string) file_get_contents($path)),
        ];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return list<array<string, mixed>>
     */
    public function auditLog(array $actor, int $limit = 100): array
    {
        $this->assertAdmin($actor);
        $limit = min(200, max(1, $limit));

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id_marketplace_audit_log'],
            'actor_role' => (string) $row['actor_role'],
            'actor_id' => (int) $row['actor_id'],
            'action' => (string) $row['action'],
            'subject' => (string) $row['subject'],
            'detail' => (string) $row['detail'],
            'date_add' => (string) $row['date_add'],
        ], $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_audit_log') . ' ORDER BY id_marketplace_audit_log DESC LIMIT ' . $limit
        ));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return list<array<string, mixed>>
     */
    public function listPayouts(array $actor, int $vendorId): array
    {
        $this->assertVendor($actor, $vendorId);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id_marketplace_payout'],
            'vendor_id' => (int) $row['id_marketplace_vendor'],
            'amount_cents' => (int) $row['amount_cents'],
            'status' => (string) $row['status'],
            'provider_id' => (string) ($row['provider_id'] ?? ''),
        ], $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_payout') . ' WHERE id_marketplace_vendor = :id ORDER BY id_marketplace_payout DESC',
            ['id' => $vendorId]
        ));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return list<array<string, mixed>>
     */
    public function permissionList(array $actor): array
    {
        $this->assertAdmin($actor);

        return $this->store->all('SELECT role, permission FROM ' . $this->t('marketplace_api_permission') . ' ORDER BY role, permission');
    }

    protected function t(string $table): string
    {
        return $this->store->t($table);
    }

    /**
     * @param array{role: string, id: int} $actor
     */
    protected function audit(array $actor, string $action, string $subject, string $detail): void
    {
        $this->store->insert('marketplace_audit_log', [
            'actor_role' => $actor['role'],
            'actor_id' => (int) $actor['id'],
            'action' => substr($action, 0, 64),
            'subject' => substr($subject, 0, 128),
            'detail' => substr($detail, 0, 512),
            'date_add' => $this->store->now(),
        ]);
    }

    protected function event(int $orderId, string $status, string $note): void
    {
        $this->store->insert('marketplace_order_event', [
            'id_marketplace_order' => $orderId,
            'status' => $status,
            'note' => substr($note, 0, 255),
            'date_add' => $this->store->now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireVendor(int $id): array
    {
        $row = $this->store->one('SELECT * FROM ' . $this->t('marketplace_vendor') . ' WHERE id_marketplace_vendor = :id', ['id' => $id]);
        if ($row === null) {
            throw new PlatformException('not_found', 'The vendor was not found.', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireShopper(int $id): array
    {
        $row = $this->store->one('SELECT * FROM ' . $this->t('marketplace_shopper') . ' WHERE id_marketplace_shopper = :id', ['id' => $id]);
        if ($row === null) {
            throw new PlatformException('not_found', 'The customer was not found.', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireOrder(int $id): array
    {
        $row = $this->store->one('SELECT * FROM ' . $this->t('marketplace_order') . ' WHERE id_marketplace_order = :id', ['id' => $id]);
        if ($row === null) {
            throw new PlatformException('not_found', 'The order was not found.', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireVendorOrder(int $id): array
    {
        $row = $this->store->one('SELECT * FROM ' . $this->t('marketplace_vendor_order') . ' WHERE id_marketplace_vendor_order = :id', ['id' => $id]);
        if ($row === null) {
            throw new PlatformException('not_found', 'The vendor order was not found.', 404);
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function vendorOrders(int $orderId): array
    {
        return $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_vendor_order') . ' WHERE id_marketplace_order = :id ORDER BY id_marketplace_vendor_order',
            ['id' => $orderId]
        );
    }

    /**
     * @param array{role: string, id: int} $actor
     */
    protected function assertAdmin(array $actor): void
    {
        if ($actor['role'] !== 'admin') {
            throw new PlatformException('forbidden', 'This action is limited to an administrator.', 403);
        }
    }

    /**
     * @param array{role: string, id: int} $actor
     */
    protected function assertShopper(array $actor): void
    {
        if ($actor['role'] !== 'shopper') {
            throw new PlatformException('forbidden', 'This action is limited to a customer.', 403);
        }
    }

    /**
     * @param array{role: string, id: int} $actor
     */
    protected function assertVendor(array $actor, int $vendorId): void
    {
        if ($actor['role'] === 'admin') {
            return;
        }
        if ($actor['role'] !== 'vendor' || (int) $actor['id'] !== $vendorId) {
            throw new PlatformException('forbidden', 'Vendor data is isolated.', 403);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function requiredText(array $input, string $key, int $min, int $max): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if (strlen($value) < $min || strlen($value) > $max) {
            throw new PlatformException('validation_error', $key . ' is required.', 422);
        }

        return $value;
    }

    protected function percent(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function runOptimizer(string $postcode, array $lines): OptimizationResult
    {
        try {
            return (new CartOptimizer(new ShippingCalculator(), $this->maxCombinations))->optimize($this->buildCart($postcode, $lines));
        } catch (\InvalidArgumentException $exception) {
            throw new PlatformException('infeasible', $exception->getMessage(), 409);
        }
    }

    /**
     * @param list<array{product_id: int, quantity: int}> $lines
     */
    private function buildCart(string $postcode, array $lines): CartInput
    {
        $selector = new ShippingRuleSelector();
        $calculator = new ShippingCalculator();
        $cartLines = [];
        $offers = [];
        foreach ($lines as $line) {
            $productId = $line['product_id'];
            $name = 'Product ' . $productId;
            $options = [];
            $rows = $this->store->all(
                'SELECT o.*, s.quantity, s.reserved_quantity, v.code AS vendor_code, v.name AS vendor_name, v.min_order_enabled, v.min_order_cents
                 FROM ' . $this->t('marketplace_vendor_offer') . ' o
                 INNER JOIN ' . $this->t('marketplace_vendor_stock') . ' s ON s.id_marketplace_vendor_offer = o.id_marketplace_vendor_offer
                 INNER JOIN ' . $this->t('marketplace_vendor') . ' v ON v.id_marketplace_vendor = o.id_marketplace_vendor
                 WHERE o.id_product = :product AND o.active = 1 AND v.status = :status',
                ['product' => $productId, 'status' => 'active']
            );
            $rules = [];
            foreach ($rows as $row) {
                $available = (int) $row['quantity'] - (int) $row['reserved_quantity'];
                if ($available < 1) {
                    continue;
                }
                $vendorId = (int) $row['id_marketplace_vendor'];
                if (!array_key_exists($vendorId, $rules)) {
                    $rules[$vendorId] = $this->store->all(
                        'SELECT * FROM ' . $this->t('marketplace_shipping_rule') . ' WHERE id_marketplace_vendor = :vendor AND active = 1 ORDER BY id_marketplace_shipping_rule',
                        ['vendor' => $vendorId]
                    );
                }
                $chosen = $selector->choose($rules[$vendorId], $postcode);
                if ($chosen === null) {
                    continue;
                }
                $prefixes = $this->prefixes((string) $chosen['postcode_prefixes']);
                if (!$calculator->postcodeAllowed($prefixes, $postcode)) {
                    continue;
                }
                if ((string) ($row['title'] ?? '') !== '') {
                    $name = (string) $row['title'];
                }
                $options[] = new OfferOption(
                    (int) $row['id_marketplace_vendor_offer'],
                    $vendorId,
                    (string) $row['vendor_code'],
                    (string) $row['vendor_name'],
                    $productId,
                    Money::afterDiscount((int) $row['price_cents'], $this->percent($row['discount_percent'])),
                    $available,
                    (int) $row['weight_grams'],
                    $this->shippingRuleFromRow($vendorId, $chosen, $prefixes, $row),
                    (int) ($row['volume_cm3'] ?? 0),
                    (int) ($row['pallet_only'] ?? 0) === 1,
                );
            }
            $cartLines[] = new CartLine($productId, $name, $line['quantity']);
            $offers[$productId] = $options;
        }

        return new CartInput($cartLines, $offers, $postcode);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOptimization(OptimizationResult $result): array
    {
        return [
            'status' => $result->status->value,
            'optimal' => $result->status === OptimizationStatus::OptimalVerified,
            'product_cents' => $result->productSubtotalCents,
            'shipping_cents' => $result->shippingTotalCents,
            'grand_cents' => $result->grandTotalCents,
            'combinations_evaluated' => $result->combinationsEvaluated,
            'search_space' => $result->searchSpace,
            'explanation' => $result->explanation,
            'lines' => array_map(static fn (AllocationLine $line): array => [
                'product_id' => $line->productId,
                'product_name' => $line->productName,
                'offer_id' => $line->offerId,
                'vendor_id' => $line->vendorId,
                'vendor_code' => $line->vendorCode,
                'quantity' => $line->quantity,
                'unit_price_cents' => $line->unitPriceCents,
                'line_total_cents' => $line->lineTotalCents,
            ], $result->lines),
            'vendors' => array_map(static fn (VendorAllocation $vendor): array => [
                'vendor_id' => $vendor->vendorId,
                'vendor_code' => $vendor->vendorCode,
                'vendor_name' => $vendor->vendorName,
                'product_cents' => $vendor->productSubtotalCents,
                'shipping_cents' => $vendor->shippingCents,
                'grand_cents' => $vendor->grandTotalCents(),
                'free_shipping' => $vendor->freeShippingApplied,
                'shipping_reason' => $vendor->shippingReason,
            ], $result->vendors),
        ];
    }

    private function ensureReservations(int $orderId): void
    {
        $this->store->transaction(function () use ($orderId): void {
            $this->releaseExpired(time());
            $now = $this->store->now();
            foreach ($this->vendorOrders($orderId) as $vendorOrder) {
                $vendorOrderId = (int) $vendorOrder['id_marketplace_vendor_order'];
                foreach ($this->orderLines($vendorOrderId) as $line) {
                    $active = $this->store->one(
                        'SELECT id_marketplace_stock_reservation FROM ' . $this->t('marketplace_stock_reservation')
                        . ' WHERE id_marketplace_vendor_order = :order_id AND id_marketplace_vendor_offer = :offer AND status = :status AND expires_at >= :now',
                        [
                            'order_id' => $vendorOrderId,
                            'offer' => (int) $line['id_marketplace_vendor_offer'],
                            'status' => 'active',
                            'now' => $now,
                        ]
                    );
                    if ($active !== null) {
                        continue;
                    }
                    $this->reserve((int) $line['id_marketplace_vendor_offer'], $vendorOrderId, (int) $line['quantity']);
                }
            }
        }, true);
    }

    private function releaseOrderStock(int $orderId): void
    {
        $this->store->transaction(function () use ($orderId): void {
            foreach ($this->vendorOrders($orderId) as $vendorOrder) {
                $this->releaseReservations((int) $vendorOrder['id_marketplace_vendor_order']);
            }
        });
    }

    private function restockIfFullyRefunded(int $vendorOrderId): void
    {
        $vendorOrder = $this->requireVendorOrder($vendorOrderId);
        if ((string) $vendorOrder['fulfillment_status'] === 'fulfilled') {
            return;
        }
        $refunded = (int) $this->store->value(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM ' . $this->t('marketplace_refund') . ' WHERE id_marketplace_vendor_order = :id AND status = :status',
            ['id' => $vendorOrderId, 'status' => 'succeeded']
        );
        if ($refunded < (int) $vendorOrder['grand_cents']) {
            return;
        }
        $this->releaseReservations($vendorOrderId);
    }

    private function reserve(int $offerId, int $vendorOrderId, int $quantity): void
    {
        if ($this->store->driver() === 'mysql') {
            $this->store->one(
                'SELECT id_marketplace_vendor_stock FROM ' . $this->t('marketplace_vendor_stock') . ' WHERE id_marketplace_vendor_offer = :id FOR UPDATE',
                ['id' => $offerId]
            );
        }
        $changed = $this->store->exec(
            'UPDATE ' . $this->t('marketplace_vendor_stock')
            . ' SET reserved_quantity = reserved_quantity + :quantity, date_upd = :now'
            . ' WHERE id_marketplace_vendor_offer = :offer AND (quantity - reserved_quantity) >= :need',
            ['quantity' => $quantity, 'now' => $this->store->now(), 'offer' => $offerId, 'need' => $quantity]
        );
        if ($changed !== 1) {
            throw new PlatformException('insufficient_stock', 'Not enough stock remains for this order.', 409);
        }
        $this->store->insert('marketplace_stock_reservation', [
            'id_marketplace_vendor_offer' => $offerId,
            'id_marketplace_vendor_order' => $vendorOrderId,
            'quantity' => $quantity,
            'status' => 'active',
            'expires_at' => date('Y-m-d H:i:s', time() + 1800),
            'date_add' => $this->store->now(),
        ]);
        $this->bumpCatalog();
    }

    private function releaseReservations(int $vendorOrderId): void
    {
        $rows = $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_stock_reservation') . ' WHERE id_marketplace_vendor_order = :id AND status = :status',
            ['id' => $vendorOrderId, 'status' => 'active']
        );
        foreach ($rows as $row) {
            $changed = $this->store->exec(
                'UPDATE ' . $this->t('marketplace_stock_reservation') . ' SET status = :released WHERE id_marketplace_stock_reservation = :id AND status = :active',
                ['released' => 'released', 'id' => (int) $row['id_marketplace_stock_reservation'], 'active' => 'active']
            );
            if ($changed !== 1) {
                continue;
            }
            $this->store->exec(
                'UPDATE ' . $this->t('marketplace_vendor_stock') . ' SET reserved_quantity = reserved_quantity - :quantity, date_upd = :now WHERE id_marketplace_vendor_offer = :offer AND reserved_quantity >= :need',
                [
                    'quantity' => (int) $row['quantity'],
                    'now' => $this->store->now(),
                    'offer' => (int) $row['id_marketplace_vendor_offer'],
                    'need' => (int) $row['quantity'],
                ]
            );
            $this->bumpCatalog();
        }
    }

    /**
     * @param array<string, mixed> $vendor
     */
    private function applyImportRow(array $vendor, ImportRow $row): string
    {
        $input = [
            'sku' => $row->sku,
            'ean' => $row->ean,
            'brand' => $row->brand,
            'title' => $row->title,
            'description' => $row->description,
            'price_cents' => $row->listPriceCents,
            'discount' => $row->discountPercent,
            'stock' => $row->stock,
            'weight_grams' => $row->weightGrams,
            'image_url' => $row->imageUrl,
        ];
        $existing = $this->store->one(
            'SELECT id_product FROM ' . $this->t('marketplace_vendor_offer') . ' WHERE id_marketplace_vendor = :vendor AND vendor_sku = :sku',
            ['vendor' => (int) $vendor['id_marketplace_vendor'], 'sku' => $row->sku]
        );
        if ($existing !== null) {
            $input['product_id'] = (int) $existing['id_product'];
        }
        $saved = $this->upsertOffer(['role' => 'vendor', 'id' => (int) $vendor['id_marketplace_vendor']], (int) $vendor['id_marketplace_vendor'], $input);

        return (string) $saved['action'];
    }

    private function matchOrCreateProduct(int $vendorId, string $sku, string $ean, string $title): int
    {
        $catalog = [];
        foreach ($this->store->all('SELECT id_product, vendor_sku, ean13, title FROM ' . $this->t('marketplace_vendor_offer') . ' ORDER BY id_product, id_marketplace_vendor_offer') as $row) {
            $id = (int) $row['id_product'];
            if (!isset($catalog[$id])) {
                $catalog[$id] = new CatalogProduct($id, (string) $row['vendor_sku'], (string) ($row['ean13'] ?? ''), (string) ($row['title'] ?? ''));
            }
        }
        $match = (new ProductMatcher())->match($ean, $sku, array_values($catalog));
        $this->store->insert('marketplace_product_match', [
            'id_marketplace_vendor' => $vendorId,
            'vendor_sku' => $sku,
            'ean13' => $ean === '' ? null : $ean,
            'id_product' => $match->type === MatchType::Exact ? $match->candidateProductIds[0] : null,
            'match_type' => $match->type->value,
            'match_key' => $match->matchedOn,
            'candidate_product_ids' => implode(',', $match->candidateProductIds),
            'date_add' => $this->store->now(),
        ]);
        if ($match->type === MatchType::Ambiguous) {
            throw new PlatformException('ambiguous_match', 'The EAN or SKU matches more than one master product.', 409);
        }
        if ($match->type === MatchType::Exact) {
            return $match->candidateProductIds[0];
        }
        $next = max(
            (int) $this->store->value('SELECT COALESCE(MAX(id_product), 0) FROM ' . $this->t('marketplace_vendor_offer')),
            (int) $this->store->value('SELECT COALESCE(MAX(id_marketplace_master_product), 0) FROM ' . $this->t('marketplace_master_product')),
        ) + 1;
        $this->store->insert('marketplace_master_product', [
            'id_marketplace_master_product' => $next,
            'ean13' => $ean === '' ? null : $ean,
            'sku' => $sku,
            'name' => $title,
            'date_add' => $this->store->now(),
        ]);

        return $next;
    }

    private function setStockQuantity(int $offerId, int $quantity): void
    {
        $stock = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_vendor_stock') . ' WHERE id_marketplace_vendor_offer = :id',
            ['id' => $offerId]
        );
        if ($stock === null) {
            $this->store->insert('marketplace_vendor_stock', [
                'id_marketplace_vendor_offer' => $offerId,
                'quantity' => $quantity,
                'reserved_quantity' => 0,
                'low_stock_threshold' => 3,
                'date_upd' => $this->store->now(),
            ]);

            return;
        }
        if ($quantity < (int) $stock['reserved_quantity']) {
            throw new PlatformException('conflict', 'Stock cannot be lower than the reserved quantity.', 409);
        }
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_vendor_stock') . ' SET quantity = :quantity, date_upd = :now WHERE id_marketplace_vendor_offer = :id',
            ['quantity' => $quantity, 'now' => $this->store->now(), 'id' => $offerId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function createInvoice(string $issuer, int $vendorId, int $orderId, int $vendorOrderId, string $kind, int $total, string $prefix): array
    {
        $existing = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_invoice') . ' WHERE id_marketplace_vendor_order = :id AND kind = :kind',
            ['id' => $vendorOrderId, 'kind' => $kind]
        );
        if ($existing !== null) {
            return $this->presentInvoice($existing);
        }
        $now = $this->store->now();
        $id = $this->store->insert('marketplace_invoice', [
            'issuer_role' => $issuer,
            'id_marketplace_vendor' => $vendorId,
            'id_marketplace_order' => $orderId,
            'id_marketplace_vendor_order' => $vendorOrderId,
            'kind' => $kind,
            'status' => 'pending',
            'number' => $this->nextNumber($prefix),
            'total_cents' => $total,
            'date_add' => $now,
            'date_upd' => $now,
        ]);

        return $this->presentInvoice($this->requireInvoice($id));
    }

    private function nextNumber(string $prefix): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = $prefix . str_pad((string) ((int) $this->store->value('SELECT COUNT(*) FROM ' . $this->t('marketplace_invoice')) + 1 + $attempt), 6, '0', STR_PAD_LEFT);
            if ($this->store->value('SELECT COUNT(*) FROM ' . $this->t('marketplace_invoice') . ' WHERE number = :number', ['number' => $number]) === '0') {
                return $number;
            }
        }
        throw new PlatformException('conflict', 'An invoice number could not be created.', 409);
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array{role: string, id: int} $actor
     */
    private function assertInvoiceAccess(array $actor, array $invoice, bool $write): void
    {
        if ($actor['role'] === 'admin') {
            return;
        }
        if ($actor['role'] === 'vendor' && (int) $actor['id'] === (int) $invoice['id_marketplace_vendor']) {
            return;
        }
        if ($write) {
            throw new PlatformException('forbidden', 'This invoice cannot be changed by this account.', 403);
        }
        if ($actor['role'] === 'shopper' && in_array((string) $invoice['kind'], ['customer', 'credit_note'], true)) {
            $order = $this->requireOrder((int) $invoice['id_marketplace_order']);
            if ((int) $order['id_marketplace_shopper'] === (int) $actor['id']) {
                return;
            }
        }
        throw new PlatformException('not_found', 'The invoice was not found.', 404);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function linkedCustomerId(array $order): ?int
    {
        $shopperId = (int) ($order['id_marketplace_shopper'] ?? 0);
        if ($shopperId < 1) {
            return null;
        }
        $shopper = $this->requireShopper($shopperId);
        $customerId = (int) ($shopper['id_customer'] ?? 0);

        return $customerId > 0 ? $customerId : null;
    }

    private function assertOrderShopper(array $actor, array $order): void
    {
        if ($actor['role'] !== 'shopper' || (int) $order['id_marketplace_shopper'] !== (int) $actor['id']) {
            throw new PlatformException('not_found', 'The order was not found.', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOrder(array $order): array
    {
        return [
            'id' => (int) $order['id_marketplace_order'],
            'reference' => (string) $order['reference'],
            'postcode' => (string) $order['postcode'],
            'product_cents' => (int) $order['product_cents'],
            'shipping_cents' => (int) $order['shipping_cents'],
            'grand_cents' => (int) $order['grand_cents'],
            'optimization_status' => (string) $order['optimization_status'],
            'optimal' => (string) $order['optimization_status'] === OptimizationStatus::OptimalVerified->value,
            'status' => (string) $order['status'],
            'payment_status' => (string) $order['payment_status'],
            'shopper_id' => $order['id_marketplace_shopper'] === null ? null : (int) $order['id_marketplace_shopper'],
            'id_customer' => $this->linkedCustomerId($order),
            'vendor_orders' => array_map(fn (array $row): array => $this->presentVendorOrder($row), $this->vendorOrders((int) $order['id_marketplace_order'])),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentVendorOrder(array $row): array
    {
        return [
            'id' => (int) $row['id_marketplace_vendor_order'],
            'order_id' => (int) $row['id_marketplace_order'],
            'vendor_id' => (int) $row['id_marketplace_vendor'],
            'reference' => (string) $row['reference'],
            'parent_reference' => (string) $row['parent_reference'],
            'product_cents' => (int) $row['product_cents'],
            'shipping_cents' => (int) $row['shipping_cents'],
            'grand_cents' => (int) $row['grand_cents'],
            'status' => (string) $row['status'],
            'fulfillment_status' => (string) ($row['fulfillment_status'] ?? 'pending'),
            'native_order_id' => isset($row['id_order']) && $row['id_order'] !== null && (int) $row['id_order'] > 0 ? (int) $row['id_order'] : null,
            'lines' => array_map(static fn (array $line): array => [
                'offer_id' => (int) $line['id_marketplace_vendor_offer'],
                'product_id' => (int) $line['id_product'],
                'product_name' => (string) $line['product_name'],
                'quantity' => (int) $line['quantity'],
                'unit_price_cents' => (int) $line['unit_price_cents'],
                'line_total_cents' => (int) $line['line_total_cents'],
            ], $this->orderLines((int) $row['id_marketplace_vendor_order'])),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderLines(int $vendorOrderId): array
    {
        return $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_vendor_order_line') . ' WHERE id_marketplace_vendor_order = :id',
            ['id' => $vendorOrderId]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentOffer(array $row): array
    {
        $quantity = array_key_exists('quantity', $row) ? (int) $row['quantity'] : null;
        $reserved = array_key_exists('reserved_quantity', $row) ? (int) $row['reserved_quantity'] : null;
        if ($quantity === null) {
            $stock = $this->store->one(
                'SELECT quantity, reserved_quantity FROM ' . $this->t('marketplace_vendor_stock') . ' WHERE id_marketplace_vendor_offer = :id',
                ['id' => (int) $row['id_marketplace_vendor_offer']]
            );
            $quantity = (int) ($stock['quantity'] ?? 0);
            $reserved = (int) ($stock['reserved_quantity'] ?? 0);
        }

        return [
            'id' => (int) $row['id_marketplace_vendor_offer'],
            'vendor_id' => (int) $row['id_marketplace_vendor'],
            'product_id' => (int) $row['id_product'],
            'sku' => (string) $row['vendor_sku'],
            'ean' => (string) ($row['ean13'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'price_cents' => (int) $row['price_cents'],
            'discount_percent' => $this->percent($row['discount_percent']),
            'weight_grams' => (int) $row['weight_grams'],
            'quantity' => $quantity,
            'reserved_quantity' => $reserved,
            'available' => $quantity - $reserved,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentVendor(array $row): array
    {
        return [
            'id' => (int) $row['id_marketplace_vendor'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'status' => (string) $row['status'],
            'commission_percent' => $this->percent($row['commission_percent']),
            'email' => (string) $row['email'],
            'description' => (string) ($row['description'] ?? ''),
            'contact_name' => (string) ($row['contact_name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'brands' => (string) ($row['brands'] ?? ''),
            'coverage' => (string) ($row['coverage'] ?? ''),
            'has_logo' => (string) ($row['logo_path'] ?? '') !== '',
            'min_order_enabled' => (int) ($row['min_order_enabled'] ?? 0) === 1,
            'min_order_cents' => (int) ($row['min_order_cents'] ?? 0),
            'vat_rate_percent' => $this->percent($row['vat_rate_percent'] ?? '0.00'),
            'processing_fee_percent' => $this->percent($row['processing_fee_percent'] ?? '0.00'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentShopper(array $row): array
    {
        return [
            'id' => (int) $row['id_marketplace_shopper'],
            'email' => (string) $row['email'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'id_customer' => isset($row['id_customer']) && $row['id_customer'] !== null && $row['id_customer'] !== '' ? (int) $row['id_customer'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentInvoice(array $row): array
    {
        return [
            'id' => (int) $row['id_marketplace_invoice'],
            'number' => (string) $row['number'],
            'issuer_role' => (string) $row['issuer_role'],
            'vendor_id' => (int) $row['id_marketplace_vendor'],
            'order_id' => $row['id_marketplace_order'] === null ? null : (int) $row['id_marketplace_order'],
            'vendor_order_id' => $row['id_marketplace_vendor_order'] === null ? null : (int) $row['id_marketplace_vendor_order'],
            'kind' => (string) $row['kind'],
            'status' => (string) $row['status'],
            'total_cents' => (int) $row['total_cents'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentJob(array $row): array
    {
        return [
            'id' => (int) $row['id_marketplace_import_job'],
            'vendor_id' => (int) $row['id_marketplace_vendor'],
            'filename' => (string) $row['filename'],
            'status' => (string) $row['status'],
            'rows_total' => (int) $row['rows_total'],
            'rows_created' => (int) $row['rows_created'],
            'rows_updated' => (int) $row['rows_updated'],
            'rows_failed' => (int) $row['rows_failed'],
            'error_log' => (string) ($row['error_log'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOffer(int $id): array
    {
        $row = $this->store->one('SELECT * FROM ' . $this->t('marketplace_vendor_offer') . ' WHERE id_marketplace_vendor_offer = :id', ['id' => $id]);
        if ($row === null) {
            throw new PlatformException('not_found', 'The offer was not found.', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireInvoice(int $id): array
    {
        $row = $this->store->one('SELECT * FROM ' . $this->t('marketplace_invoice') . ' WHERE id_marketplace_invoice = :id', ['id' => $id]);
        if ($row === null) {
            throw new PlatformException('not_found', 'The invoice was not found.', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireJob(int $id): array
    {
        $row = $this->store->one('SELECT * FROM ' . $this->t('marketplace_import_job') . ' WHERE id_marketplace_import_job = :id', ['id' => $id]);
        if ($row === null) {
            throw new PlatformException('not_found', 'The import was not found.', 404);
        }

        return $row;
    }

    /**
     * @return array{token: string, token_type: string, role: string, subject_id: int, expires_at: int}
     */
    private function issueToken(string $role, int $subjectId): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = time() + (30 * 86400);
        $this->store->insert('marketplace_api_credential', [
            'role' => $role,
            'subject_id' => $subjectId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expires,
            'date_add' => $this->store->now(),
        ]);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'role' => $role,
            'subject_id' => $subjectId,
            'expires_at' => $expires,
        ];
    }

    private function reference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = 'MP' . date('ymdHis') . random_int(100, 999);
            if ($this->store->value('SELECT COUNT(*) FROM ' . $this->t('marketplace_order') . ' WHERE reference = :reference', ['reference' => $reference]) === '0') {
                return $reference;
            }
        }
        throw new PlatformException('conflict', 'A unique order reference could not be created.', 409);
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array{product_id: int, quantity: int}>
     */
    private function cartLines(array $lines): array
    {
        if ($lines === [] || count($lines) > 50) {
            throw new PlatformException('validation_error', 'The cart needs between 1 and 50 lines.', 422);
        }
        $merged = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new PlatformException('validation_error', 'Each cart line must be an object.', 422);
            }
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($productId < 1 || $quantity < 1 || $quantity > 999) {
                throw new PlatformException('validation_error', 'Product quantity must be between 1 and 999.', 422);
            }
            $merged[$productId] = ($merged[$productId] ?? 0) + $quantity;
            if ($merged[$productId] > 999) {
                throw new PlatformException('validation_error', 'Product quantity must be between 1 and 999.', 422);
            }
        }
        $result = [];
        foreach ($merged as $productId => $quantity) {
            $result[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        return $result;
    }

    private function postcode(string $value): string
    {
        $value = strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $value)) {
            throw new PlatformException('validation_error', 'Postcode must be 2 to 12 letters or digits.', 422);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function prefixes(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $prefixes = [];
        foreach (explode(',', $value) as $prefix) {
            $prefix = strtoupper(preg_replace('/\s+/', '', $prefix) ?? '');
            if ($prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    private function touchSchedule(int $id, int $now): void
    {
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_import_schedule') . ' SET last_run = :now WHERE id_marketplace_import_schedule = :id',
            ['now' => $now, 'id' => $id]
        );
    }

    private function csvCell(string $value): string
    {
        if (preg_match('/^[=+\\-@\\t\\r]/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param list<list<string>> $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'rb+');
        if ($handle === false) {
            throw new PlatformException('storage_unavailable', 'The export could not be created.', 500);
        }
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
    }

    private function kilograms(int $grams): string
    {
        $whole = intdiv($grams, 1000);
        $fraction = str_pad((string) ($grams % 1000), 3, '0', STR_PAD_LEFT);

        return $whole . '.' . $fraction;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalText(array $input, string $key, int $max): ?string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $max) {
            throw new PlatformException('validation_error', $key . ' is too long.', 422);
        }

        return $value;
    }

    private function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PlatformException('validation_error', 'A valid email is required.', 422);
        }

        return $email;
    }

    private function password(string $password): string
    {
        if (strlen($password) < 12 || strlen($password) > 128) {
            throw new PlatformException('validation_error', 'Password must be 12 to 128 characters.', 422);
        }

        return $password;
    }

    private function vendorCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{1,8}$/', $code)) {
            throw new PlatformException('validation_error', 'Vendor code must be 1 to 8 letters or digits.', 422);
        }

        return $code;
    }

    private function generatedCode(string $name): string
    {
        $letter = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? 'V', 0, 1));
        if ($letter === '') {
            $letter = 'V';
        }
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = $letter . random_int(100, 999);
            if ($this->store->value('SELECT COUNT(*) FROM ' . $this->t('marketplace_vendor') . ' WHERE code = :code', ['code' => $code]) === '0') {
                return $code;
            }
        }
        throw new PlatformException('conflict', 'A vendor code could not be allocated.', 409);
    }

    private function slug(string $name, string $code): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'vendor';
        }

        return substr($base, 0, 40) . '-' . strtolower($code);
    }

    private function sku(string $sku): string
    {
        $sku = trim($sku);
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $sku)) {
            throw new PlatformException('validation_error', 'SKU contains unsupported characters.', 422);
        }

        return $sku;
    }

    private function discount(string $value): string
    {
        if ($value === '') {
            $value = '0';
        }
        try {
            $basisPoints = Money::basisPoints($value);
        } catch (\InvalidArgumentException $exception) {
            throw new PlatformException('validation_error', $exception->getMessage(), 422);
        }

        return intdiv($basisPoints, 100) . '.' . str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }

    private function cents(mixed $value, string $label): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
            throw new PlatformException('validation_error', $label . ' must be a whole number of cents.', 422);
        }
        $cents = (int) $value;
        if ($cents < 0 || $cents > 100000000) {
            throw new PlatformException('validation_error', $label . ' is out of range.', 422);
        }

        return $cents;
    }

    private function quantity(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
            throw new PlatformException('validation_error', 'Quantity must be a whole number.', 422);
        }
        $quantity = (int) $value;
        if ($quantity < 0 || $quantity > 1000000) {
            throw new PlatformException('validation_error', 'Quantity is out of range.', 422);
        }

        return $quantity;
    }

    private function imageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strlen($url) > 512 || !preg_match('#^https?://#i', $url)) {
            throw new PlatformException('validation_error', 'Image URL must be an HTTP or HTTPS URL.', 422);
        }

        return $url;
    }

    private function filename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $name)) {
            throw new PlatformException('validation_error', 'Filename contains unsupported characters.', 422);
        }

        return $name;
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function pageBounds(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /**
     * @return never
     */
    private function unique(PDOException $exception, string $message): void
    {
        if ($this->store->isUniqueViolation($exception)) {
            throw new PlatformException('conflict', $message, 409);
        }
        throw $exception;
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<string> $prefixes
     * @param array<string, mixed> $vendor
     */
    private function shippingRuleFromRow(int $vendorId, array $rule, array $prefixes, array $vendor): ShippingRule
    {
        $threshold = $rule['free_threshold_cents'] ?? null;
        $palletFree = $rule['pallet_free_threshold_cents'] ?? null;

        return new ShippingRule(
            $vendorId,
            (int) $rule['base_cents'],
            $threshold === null || $threshold === '' ? null : (int) $threshold,
            (int) $rule['surcharge_cents_per_kg'],
            $prefixes,
            (int) ($rule['pallet_base_cents'] ?? 0),
            $palletFree === null || $palletFree === '' ? null : (int) $palletFree,
            $this->nullableInt($rule['parcel_max_weight_grams'] ?? null),
            $this->nullableInt($rule['parcel_max_volume_cm3'] ?? null),
            $this->nullableInt($rule['pallet_capacity_weight_grams'] ?? null),
            $this->nullableInt($rule['pallet_capacity_volume_cm3'] ?? null),
            (int) ($vendor['min_order_enabled'] ?? 0) === 1,
            (int) ($vendor['min_order_cents'] ?? 0),
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalPositive(array $input, string $key): ?int
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        $value = $this->cents($input[$key], $key);

        return $value > 0 ? $value : null;
    }

    private function bumpCatalog(): void
    {
        RedisClient::fromEnvironment()?->incr('mp:catalog:version');
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function requestCustomerLink(array $actor, int $customerId): array
    {
        $this->assertShopper($actor);
        if ($customerId < 1) {
            throw new PlatformException('validation_error', 'A PrestaShop customer id is required.', 422);
        }
        $code = bin2hex(random_bytes(8));
        $this->store->insert('marketplace_customer_link', [
            'id_marketplace_shopper' => (int) $actor['id'],
            'id_customer' => $customerId,
            'code_hash' => hash('sha256', $code),
            'expires_at' => time() + 1800,
            'consumed' => 0,
            'date_add' => $this->store->now(),
        ]);
        $this->audit($actor, 'customer.link_requested', 'shopper:' . $actor['id'], 'customer:' . $customerId);

        return ['id_customer' => $customerId, 'code' => $code, 'expires_in' => 1800];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function confirmCustomerLink(array $actor, int $shopperId, int $customerId, string $code): array
    {
        $this->assertAdmin($actor);
        if ($shopperId < 1 || $customerId < 1 || !preg_match('/^[a-f0-9]{16}$/', $code)) {
            throw new PlatformException('validation_error', 'The link confirmation is invalid.', 422);
        }
        $this->requireShopper($shopperId);
        $taken = $this->store->one(
            'SELECT id_marketplace_shopper FROM ' . $this->t('marketplace_shopper') . ' WHERE id_customer = :customer AND id_marketplace_shopper <> :shopper',
            ['customer' => $customerId, 'shopper' => $shopperId]
        );
        if ($taken !== null) {
            throw new PlatformException('conflict', 'That storefront customer is already linked.', 409);
        }
        $challenge = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_customer_link')
            . ' WHERE id_marketplace_shopper = :shopper AND id_customer = :customer AND consumed = 0 AND expires_at >= :now AND code_hash = :hash ORDER BY id_marketplace_customer_link DESC',
            ['shopper' => $shopperId, 'customer' => $customerId, 'now' => time(), 'hash' => hash('sha256', $code)]
        );
        if ($challenge === null) {
            throw new PlatformException('forbidden', 'The link code does not match this shopper and customer.', 403);
        }
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_customer_link') . ' SET consumed = 1 WHERE id_marketplace_customer_link = :id',
            ['id' => (int) $challenge['id_marketplace_customer_link']]
        );
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_shopper') . ' SET id_customer = :customer WHERE id_marketplace_shopper = :id',
            ['customer' => $customerId, 'id' => $shopperId]
        );
        $this->audit($actor, 'customer.linked', 'shopper:' . $shopperId, 'customer:' . $customerId);

        return $this->presentShopper($this->requireShopper($shopperId));
    }

    /**
     * @return array<string, mixed>
     */
    public function requestPasswordReset(string $role, string $email): array
    {
        if (!in_array($role, ['shopper', 'vendor'], true)) {
            throw new PlatformException('validation_error', 'role is invalid.', 422);
        }
        $email = $this->email($email);
        $response = ['accepted' => true];
        $table = $role === 'vendor' ? 'marketplace_vendor' : 'marketplace_shopper';
        $row = $this->store->one('SELECT email FROM ' . $this->t($table) . ' WHERE email = :email', ['email' => $email]);
        if ($row === null) {
            return $response;
        }
        $token = bin2hex(random_bytes(16));
        $this->store->insert('marketplace_api_reset', [
            'role' => $role,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => time() + 1800,
            'consumed' => 0,
            'date_add' => $this->store->now(),
        ]);
        $this->audit(['role' => 'system', 'id' => 0], 'auth.reset_requested', $role, $email);
        if ($this->exposeResetTokens) {
            $response['token'] = $token;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmPasswordReset(string $role, string $email, string $token, string $password): array
    {
        if (!in_array($role, ['shopper', 'vendor'], true) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new PlatformException('validation_error', 'The reset token is invalid.', 422);
        }
        $email = $this->email($email);
        $password = $this->password($password);
        $row = $this->store->one(
            'SELECT * FROM ' . $this->t('marketplace_api_reset')
            . ' WHERE role = :role AND email = :email AND token_hash = :hash AND consumed = 0 AND expires_at >= :now ORDER BY id_marketplace_api_reset DESC',
            ['role' => $role, 'email' => $email, 'hash' => hash('sha256', $token), 'now' => time()]
        );
        if ($row === null) {
            throw new PlatformException('forbidden', 'The reset token is invalid.', 403);
        }
        $table = $role === 'vendor' ? 'marketplace_vendor' : 'marketplace_shopper';
        $this->store->exec(
            'UPDATE ' . $this->t($table) . ' SET password_hash = :hash WHERE email = :email',
            ['hash' => password_hash($password, PASSWORD_DEFAULT), 'email' => $email]
        );
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_api_reset') . ' SET consumed = 1 WHERE id_marketplace_api_reset = :id',
            ['id' => (int) $row['id_marketplace_api_reset']]
        );
        $this->audit(['role' => 'system', 'id' => 0], 'auth.reset_confirmed', $role, $email);

        return ['reset' => true];
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function shopperProfile(array $actor): array
    {
        $this->assertShopper($actor);

        return $this->presentShopper($this->requireShopper((int) $actor['id']));
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function searchVendors(string $query, int $page, int $perPage): array
    {
        [$page, $perPage, $offset] = $this->pageBounds($page, $perPage);
        $query = trim($query);
        if (strlen($query) > 80) {
            throw new PlatformException('validation_error', 'The vendor search is too long.', 422);
        }
        $where = ' WHERE status = :status';
        $params = ['status' => 'active'];
        if ($query !== '') {
            $where .= ' AND name LIKE :name';
            $params['name'] = '%' . str_replace(['%', '_'], '', $query) . '%';
        }
        $total = (int) $this->store->value('SELECT COUNT(*) FROM ' . $this->t('marketplace_vendor') . $where, $params);
        $rows = $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_vendor') . $where . ' ORDER BY name LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['data' => array_map($this->publicVendorRow(...), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicVendor(int $vendorId): array
    {
        $vendor = $this->requireVendor($vendorId);
        if ((string) $vendor['status'] !== 'active') {
            throw new PlatformException('not_found', 'The vendor was not found.', 404);
        }

        return $this->publicVendorRow($vendor);
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function uploadLogo(array $actor, string $encoded): array
    {
        $this->assertVendor($actor, (int) $actor['id']);
        $binary = base64_decode($encoded, true);
        if (!is_string($binary) || strlen($binary) < 8 || strlen($binary) > 1024 * 1024) {
            throw new PlatformException('payload_too_large', 'The logo must be a PNG or JPEG under 1 MB.', 413);
        }
        $png = str_starts_with($binary, "\x89PNG\r\n\x1a\n");
        $jpeg = str_starts_with($binary, "\xFF\xD8\xFF");
        if (!$png && !$jpeg) {
            throw new PlatformException('validation_error', 'The logo must be a PNG or JPEG.', 422);
        }
        $name = bin2hex(random_bytes(16)) . ($png ? '.png' : '.jpg');
        $directory = dirname($this->invoiceDirectory) . DIRECTORY_SEPARATOR . 'marketplace-logos';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PlatformException('storage_unavailable', 'Logo storage is not available.', 500);
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($path, $binary) === false) {
            throw new PlatformException('storage_unavailable', 'The logo could not be stored.', 500);
        }
        chmod($path, 0600);
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_vendor') . ' SET logo_path = :path, date_upd = :now WHERE id_marketplace_vendor = :id',
            ['path' => $name, 'now' => $this->store->now(), 'id' => (int) $actor['id']]
        );
        $this->audit($actor, 'vendor.logo', 'vendor:' . $actor['id'], $name);

        return ['has_logo' => true];
    }

    /**
     * @param array{role: string, id: int} $actor
     */
    public function setVatRate(array $actor, int $vendorId, string $percent): array
    {
        $this->assertAdmin($actor);
        $this->requireVendor($vendorId);
        $normalized = $this->percent($percent);
        $basis = Money::basisPoints($normalized);
        if ($basis < 0 || $basis > 10000) {
            throw new PlatformException('validation_error', 'VAT rate is out of range.', 422);
        }
        $this->store->exec(
            'UPDATE ' . $this->t('marketplace_vendor') . ' SET vat_rate_percent = :rate, date_upd = :now WHERE id_marketplace_vendor = :id',
            ['rate' => $normalized, 'now' => $this->store->now(), 'id' => $vendorId]
        );
        $this->audit($actor, 'vendor.vat', 'vendor:' . $vendorId, $normalized);

        return $this->presentVendor($this->requireVendor($vendorId));
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function statement(array $actor, int $vendorId, string $month): array
    {
        $this->assertVendor($actor, $vendorId);
        [$start, $end] = $this->monthWindow($month);
        $rows = $this->store->all(
            'SELECT * FROM ' . $this->t('marketplace_commission')
            . ' WHERE id_marketplace_vendor = :id AND date_add >= :start AND date_add < :end ORDER BY id_marketplace_commission',
            ['id' => $vendorId, 'start' => $start, 'end' => $end]
        );
        $vendor = $this->requireVendor($vendorId);
        $feePercent = $this->percent($vendor['processing_fee_percent'] ?? '0.00');
        $lines = [];
        $commission = 0;
        $vat = 0;
        $net = 0;
        $gross = 0;
        $processing = 0;
        foreach ($rows as $row) {
            $grossCents = (int) $row['gross_cents'];
            $fee = Money::percentOf(abs($grossCents), $feePercent);
            if ($grossCents < 0) {
                $fee = -$fee;
            }
            $commission += (int) $row['commission_cents'];
            $vat += (int) ($row['vat_cents'] ?? 0);
            $net += (int) $row['net_cents'];
            $gross += $grossCents;
            $processing += $fee;
            $lines[] = [
                'vendor_order_id' => $row['id_marketplace_vendor_order'] === null ? null : (int) $row['id_marketplace_vendor_order'],
                'gross_cents' => $grossCents,
                'commission_cents' => (int) $row['commission_cents'],
                'vat_cents' => (int) ($row['vat_cents'] ?? 0),
                'processing_fee_cents' => $fee,
                'net_cents' => (int) $row['net_cents'],
                'date' => (string) $row['date_add'],
            ];
        }

        return [
            'month' => $month,
            'vendor_id' => $vendorId,
            'gross_cents' => $gross,
            'commission_cents' => $commission,
            'vat_cents' => $vat,
            'processing_fee_cents' => $processing,
            'net_cents' => $net,
            'lines' => $lines,
        ];
    }

    /**
     * @param array{role: string, id: int} $actor
     */
    public function exportStatement(array $actor, int $vendorId, string $month): string
    {
        $statement = $this->statement($actor, $vendorId, $month);
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new PlatformException('storage_unavailable', 'The statement could not be exported.', 500);
        }
        fputcsv($handle, ['month', 'vendor_order_id', 'gross_cents', 'commission_cents', 'vat_cents', 'processing_fee_cents', 'net_cents', 'date']);
        foreach ($statement['lines'] as $line) {
            fputcsv($handle, [
                $this->csvCell((string) $statement['month']),
                $this->csvCell((string) ($line['vendor_order_id'] ?? '')),
                $this->csvCell((string) $line['gross_cents']),
                $this->csvCell((string) $line['commission_cents']),
                $this->csvCell((string) $line['vat_cents']),
                $this->csvCell((string) $line['processing_fee_cents']),
                $this->csvCell((string) $line['net_cents']),
                $this->csvCell((string) $line['date']),
            ]);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param array{role: string, id: int} $actor
     * @return array<string, mixed>
     */
    public function createCreditNote(array $actor, int $vendorOrderId): array
    {
        $order = $this->requireVendorOrder($vendorOrderId);
        $this->assertVendor($actor, (int) $order['id_marketplace_vendor']);
        $refunded = (int) $this->store->value(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM ' . $this->t('marketplace_refund') . ' WHERE id_marketplace_vendor_order = :id AND status = :status',
            ['id' => $vendorOrderId, 'status' => 'succeeded']
        );
        if ($refunded < 1) {
            throw new PlatformException('conflict', 'A credit note requires a refund on this shipment.', 409);
        }

        return $this->createInvoice('vendor', (int) $order['id_marketplace_vendor'], (int) $order['id_marketplace_order'], $vendorOrderId, 'credit_note', $refunded, 'VCN-');
    }

    /**
     * @param list<array{vendor_reference: string, id_order: int}> $created
     */
    public function publishNativeOrder(int $orderId): void
    {
        if (!$this->nativeOrders instanceof NativeOrderPort) {
            return;
        }
        $order = $this->requireOrder($orderId);
        if ((string) $order['payment_status'] !== 'paid') {
            return;
        }
        $shopperId = (int) ($order['id_marketplace_shopper'] ?? 0);
        if ($shopperId < 1) {
            return;
        }
        $shopper = $this->requireShopper($shopperId);
        $customerId = (int) ($shopper['id_customer'] ?? 0);
        if ($customerId < 1) {
            return;
        }
        $linked = (int) $this->store->value(
            'SELECT COUNT(*) FROM ' . $this->t('marketplace_vendor_order') . ' WHERE id_marketplace_order = :id AND id_order IS NOT NULL AND id_order <> 0',
            ['id' => $orderId]
        );
        if ($linked > 0) {
            return;
        }
        try {
            $created = $this->nativeOrders->createForPaidOrder((string) $order['reference'], $customerId);
            foreach ($created as $row) {
                $this->store->exec(
                    'UPDATE ' . $this->t('marketplace_vendor_order') . ' SET id_order = :native_id, shop_reference = :reference WHERE id_marketplace_order = :order AND reference = :reference AND (id_order IS NULL OR id_order = 0)',
                    ['native_id' => (int) $row['id_order'], 'reference' => (string) $row['vendor_reference'], 'order' => $orderId]
                );
            }
            $this->audit(['role' => 'system', 'id' => 0], 'order.native_created', 'order:' . $orderId, 'customer:' . $customerId);
        } catch (\Throwable $exception) {
            $this->audit(['role' => 'system', 'id' => 0], 'order.native_failed', 'order:' . $orderId, $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicVendorRow(array $row): array
    {
        return [
            'id' => (int) $row['id_marketplace_vendor'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => (string) ($row['description'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'brands' => (string) ($row['brands'] ?? ''),
            'coverage' => (string) ($row['coverage'] ?? ''),
            'has_logo' => (string) ($row['logo_path'] ?? '') !== '',
            'min_order_enabled' => (int) ($row['min_order_enabled'] ?? 0) === 1,
            'min_order_cents' => (int) ($row['min_order_cents'] ?? 0),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function monthWindow(string $month): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $matches)) {
            throw new PlatformException('validation_error', 'Month must be YYYY-MM.', 422);
        }
        $year = (int) $matches[1];
        $monthNumber = (int) $matches[2];
        if ($monthNumber < 1 || $monthNumber > 12) {
            throw new PlatformException('validation_error', 'Month must be YYYY-MM.', 422);
        }
        $start = sprintf('%04d-%02d-01 00:00:00', $year, $monthNumber);
        $nextMonth = $monthNumber === 12 ? 1 : $monthNumber + 1;
        $nextYear = $monthNumber === 12 ? $year + 1 : $year;

        return [$start, sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth)];
    }
}
