<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Marketplace\Platform\Api\RouteMap;

$root = dirname(__DIR__);
$routes = RouteMap::routes();
$openapi = buildOpenApi($routes);
$collection = buildCollection($routes);
$local = environment('PrestaShop Local', 'http://localhost:8080');
$staging = environment('PrestaShop Staging', 'https://staging.example.invalid');

write($root . '/docs/openapi.yaml', $openapi);
write($root . '/postman/PrestaShop_Marketplace_API.postman_collection.json', json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
write($root . '/postman/PrestaShop_Local.postman_environment.json', json_encode($local, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
write($root . '/postman/PrestaShop_Staging.postman_environment.json', json_encode($staging, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDOUT, 'Wrote ' . count($routes) . " routes.\n");

/**
 * @param list<array{method: string, path: string, name: string, roles: list<string>, permission: string, tag: string, summary: string}> $routes
 */
function buildOpenApi(array $routes): string
{
    $paths = [];
    foreach ($routes as $route) {
        $paths[$route['path']][strtolower($route['method'])] = operation($route);
    }
    $document = [
        'openapi' => '3.0.3',
        'info' => [
            'title' => 'Prista Market Marketplace API',
            'version' => '1.0.0',
            'description' => "Versioned marketplace API under /api/v1.\n\nMoney is integer cents. Authentication is a bearer token returned by login. Authenticated calls are limited to 120 requests per 60 seconds. GET /api/v1/health is not limited. Checkout, payment, refund and payout require an Idempotency-Key header of 8 to 80 characters. The API does not accept raw card numbers or CVV values. live_provider_verified is always false until a real Mangopay sandbox call is recorded.",
        ],
        'servers' => [
            ['url' => 'http://localhost:8080', 'description' => 'Local Docker shop'],
            ['url' => 'https://staging.example.invalid', 'description' => 'Staging placeholder. Not a live host.'],
        ],
        'tags' => array_map(static fn (string $tag): array => ['name' => $tag], array_values(array_unique(array_column($routes, 'tag')))),
        'paths' => $paths,
        'components' => [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'token',
                    'description' => '64-character hex token from POST /api/v1/auth/login. Tokens last 30 days.',
                ],
            ],
            'parameters' => [
                'Page' => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                'PerPage' => ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]],
            ],
            'schemas' => [
                'Error' => [
                    'type' => 'object',
                    'required' => ['error'],
                    'properties' => [
                        'error' => [
                            'type' => 'object',
                            'required' => ['code', 'message'],
                            'properties' => [
                                'code' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'PageMeta' => [
                    'type' => 'object',
                    'properties' => [
                        'page' => ['type' => 'integer'],
                        'per_page' => ['type' => 'integer'],
                        'total' => ['type' => 'integer'],
                    ],
                ],
            ],
        ],
    ];

    return yaml($document);
}

/**
 * @param array{method: string, path: string, name: string, roles: list<string>, permission: string, tag: string, summary: string} $route
 * @return array<string, mixed>
 */
function operation(array $route): array
{
    $operation = [
        'tags' => [$route['tag']],
        'operationId' => $route['name'],
        'summary' => $route['summary'],
        'description' => description($route),
        'responses' => [
            (string) successStatus($route['name']) => [
                'description' => 'Success envelope. List routes also return meta.page, meta.per_page and meta.total.',
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object'], 'meta' => ['$ref' => '#/components/schemas/PageMeta']]]]],
            ],
            '401' => errorResponse('Authentication is missing or the webhook could not be verified.'),
            '403' => errorResponse('The account role or vendor scope does not allow this call.'),
            '404' => errorResponse('The route or record was not found. Another customer\'s order is returned as 404.'),
            '409' => errorResponse('The cart is infeasible, stock is insufficient, or the record conflicts.'),
            '422' => errorResponse('The request failed validation.'),
            '429' => errorResponse('The caller exceeded 120 requests in 60 seconds.'),
        ],
    ];
    if ($route['roles'] !== []) {
        $operation['security'] = [['bearerAuth' => []]];
    }
    $parameters = [];
    if (preg_match_all('#\{([A-Za-z]+)\}#', $route['path'], $names)) {
        foreach ($names[1] as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ];
        }
    }
    if (isListRoute($route['name'])) {
        $parameters[] = ['$ref' => '#/components/parameters/Page'];
        $parameters[] = ['$ref' => '#/components/parameters/PerPage'];
    }
    foreach (queryParameters($route['name']) as $parameter) {
        $parameters[] = $parameter;
    }
    if ($parameters !== []) {
        $operation['parameters'] = $parameters;
    }
    if (in_array($route['method'], ['POST', 'PUT'], true)) {
        $operation['requestBody'] = [
            'required' => $route['name'] !== 'maintenance',
            'content' => [
                'application/json' => [
                    'schema' => ['type' => 'object'],
                    'example' => exampleBody($route['name']),
                ],
            ],
        ];
    }
    if (needsIdempotency($route['name'])) {
        $operation['parameters'][] = [
            'name' => 'Idempotency-Key',
            'in' => 'header',
            'required' => true,
            'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 80],
            'example' => 'demo-' . $route['name'] . '-0001',
        ];
    }

    return $operation;
}

/**
 * @param array{name: string, roles: list<string>, permission: string} $route
 */
function description(array $route): string
{
    $roles = $route['roles'] === [] ? 'public' : implode(', ', $route['roles']);

    return 'Permission ' . $route['permission'] . '. Roles: ' . $roles . '.';
}

function successStatus(string $name): int
{
    return match ($name) {
        'registerVendor', 'registerCustomer', 'shippingRule', 'upsertOffer', 'checkout', 'identity', 'refund', 'payout', 'import', 'scheduleImport', 'createInvoice', 'commissionInvoice', 'linkChallenge', 'creditNote' => 201,
        'pay' => 201,
        default => 200,
    };
}

function isListRoute(string $name): bool
{
    return in_array($name, ['products', 'offers', 'inventory', 'orders', 'vendorOrders', 'invoices', 'adminVendors', 'adminOrders', 'vendorSearch'], true);
}

/**
 * @return list<array<string, mixed>>
 */
function queryParameters(string $name): array
{
    if ($name === 'vendorSearch') {
        return [
            ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 80]],
        ];
    }
    if ($name === 'statement' || $name === 'exportStatement') {
        return [
            ['name' => 'month', 'in' => 'query', 'schema' => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}$']],
        ];
    }
    if ($name !== 'offers') {
        return [];
    }

    return [
        ['name' => 'vendor_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
        ['name' => 'vendor_ids', 'in' => 'query', 'schema' => ['type' => 'string', 'description' => 'Comma-separated vendor ids, at most 20.']],
        ['name' => 'product_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
        ['name' => 'ean', 'in' => 'query', 'schema' => ['type' => 'string']],
        ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['id', 'price_cents', 'sku']]],
        ['name' => 'direction', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']]],
    ];
}

function needsIdempotency(string $name): bool
{
    return in_array($name, ['checkout', 'pay', 'refund', 'payout'], true);
}

/**
 * @return array<string, mixed>
 */
function exampleBody(string $name): array
{
    return match ($name) {
        'registerVendor' => ['name' => 'Example Goods', 'email' => 'vendor@example.com', 'password' => 'replace-with-12-plus', 'code' => 'E'],
        'registerCustomer' => ['email' => 'shopper@example.com', 'password' => 'replace-with-12-plus', 'first_name' => 'Nora', 'last_name' => 'Keller'],
        'login' => ['role' => 'shopper', 'email' => 'shopper@example.com', 'password' => 'replace-with-12-plus'],
        'passwordReset' => ['role' => 'shopper', 'email' => 'shopper@example.com'],
        'passwordConfirm' => ['role' => 'shopper', 'email' => 'shopper@example.com', 'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'password' => 'replacement-password-1'],
        'linkChallenge' => ['id_customer' => 3],
        'confirmLink' => ['shopper_id' => 1, 'id_customer' => 3, 'code' => 'aaaaaaaaaaaaaaaa'],
        'updateVendor' => ['name' => 'Example Goods', 'description' => 'Wholesale pantry', 'contact_name' => 'Eva', 'phone' => '+4930123456', 'city' => 'Berlin', 'brands' => 'Alpine', 'coverage' => 'DE', 'min_order_enabled' => false, 'min_order_cents' => 0],
        'shippingRule' => ['name' => 'Nationwide', 'base_cents' => 500, 'free_threshold_cents' => 5000, 'surcharge_cents_per_kg' => 0, 'postcode_prefixes' => [], 'pallet_base_cents' => 6500, 'pallet_free_threshold_cents' => null],
        'upsertOffer' => ['product_id' => 2, 'sku' => 'MUESLI-1KG', 'ean' => '', 'title' => 'Alpine Muesli 1kg', 'price_cents' => 3600, 'discount' => '0', 'stock' => 8, 'weight_grams' => 1000, 'volume_cm3' => 1200, 'pallet_only' => false],
        'setStock' => ['quantity' => 8],
        'quote' => ['vendor_id' => 1, 'postcode' => '10115', 'product_cents' => 1000, 'weight_grams' => 1000],
        'optimize', 'validate' => ['postcode' => '10115', 'lines' => [['product_id' => 2, 'quantity' => 1], ['product_id' => 3, 'quantity' => 1]]],
        'checkout' => ['postcode' => '10115', 'lines' => [['product_id' => 50, 'quantity' => 1]]],
        'pay' => ['order_id' => 1, 'card_id' => 'card_ok', 'return_url' => 'https://shop.example/return'],
        'webhook' => ['EventType' => 'PAYIN_NORMAL_SUCCEEDED', 'RessourceId' => 'payin_example'],
        'identity' => ['legal_type' => 'natural', 'first_name' => 'Lena', 'last_name' => 'Vogel', 'country' => 'DE', 'nationality' => 'DE', 'birthday' => '1990-01-02'],
        'refund' => ['order_id' => 1, 'amount_cents' => 2500],
        'payout' => ['amount_cents' => 1000],
        'import', 'scheduleImport' => ['filename' => 'offers.csv', 'csv' => "vendor,sku,ean,brand,title,description,price,discount,stock,weight,image_url\nE,SOAP-1,,Clean,Soap,Bar,3.40,0,10,0.5,https://cdn.example/soap.jpg\n", 'interval_minutes' => 60],
        'uploadInvoice' => ['content_base64' => 'JVBERi0xLjQK'],
        'uploadLogo' => ['content_base64' => 'iVBORw0KGgo='],
        'createInvoice', 'commissionInvoice', 'creditNote' => ['vendor_order_id' => 1],
        'setCommission' => ['commission_percent' => '10.00'],
        'setVat' => ['vat_rate_percent' => '22.00'],
        default => new stdClass() === null ? [] : [],
    };
}

/**
 * @return array<string, mixed>
 */
function errorResponse(string $description): array
{
    return [
        'description' => $description,
        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
    ];
}

/**
 * @param list<array{method: string, path: string, name: string, roles: list<string>, permission: string, tag: string, summary: string}> $routes
 * @return array<string, mixed>
 */
function buildCollection(array $routes): array
{
    $folders = [];
    foreach ($routes as $route) {
        $folders[$route['tag']][] = requestItem($route);
    }
    $items = [];
    foreach ($folders as $name => $requests) {
        $items[] = ['name' => $name, 'item' => $requests];
    }

    return [
        'info' => [
            'name' => 'PrestaShop Marketplace API',
            'description' => 'Generated from Marketplace\\Platform\\Api\\RouteMap. No live credentials are included.',
            'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        ],
        'event' => [[
            'listen' => 'prerequest',
            'script' => [
                'type' => 'text/javascript',
                'exec' => authScript(),
            ],
        ]],
        'item' => $items,
        'variable' => environmentValues('http://localhost:8080'),
    ];
}

/**
 * @return list<string>
 */
function authScript(): array
{
    return [
        'if (pm.environment.get("auth_ready") === "yes") { return; }',
        'const base = pm.environment.get("base_url") || pm.collectionVariables.get("base_url");',
        'const password = "replace-with-12-plus";',
        'const login = (role, email) => new Promise((resolve, reject) => {',
        '  pm.sendRequest({',
        '    url: base + "/api/v1/auth/login",',
        '    method: "POST",',
        '    header: { "Content-Type": "application/json", "Accept": "application/json" },',
        '    body: { mode: "raw", raw: JSON.stringify({ role: role, email: email, password: password }) }',
        '  }, (err, res) => {',
        '    if (err || !res || res.code !== 200) { reject(new Error(role + " login failed")); return; }',
        '    const token = res.json().data && res.json().data.token;',
        '    if (!token) { reject(new Error(role + " token missing")); return; }',
        '    pm.environment.set(role + "_token", token);',
        '    pm.collectionVariables.set(role + "_token", token);',
        '    resolve(token);',
        '  });',
        '});',
        'Promise.all([',
        '  login("admin", "admin@example.com"),',
        '  login("vendor", "vendor@example.com"),',
        '  login("shopper", "shopper@example.com"),',
        '  login("shopper", "shopper@example.com")',
        ']).then((tokens) => {',
        '  pm.environment.set("token", tokens[2]);',
        '  pm.environment.set("logout_token", tokens[3]);',
        '  pm.collectionVariables.set("token", tokens[2]);',
        '  pm.collectionVariables.set("logout_token", tokens[3]);',
        '  pm.environment.set("auth_ready", "yes");',
        '}).catch((error) => { console.log(String(error)); });',
    ];
}

/**
 * @param array{method: string, path: string, name: string, roles: list<string>, permission: string, summary: string} $route
 * @return array<string, mixed>
 */
function requestItem(array $route): array
{
    $headers = [['key' => 'Accept', 'value' => 'application/json']];
    if (in_array($route['method'], ['POST', 'PUT'], true)) {
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
    }
    if ($route['roles'] !== []) {
        $headers[] = ['key' => 'Authorization', 'value' => 'Bearer {{' . authToken($route) . '}}'];
    }
    if (needsIdempotency($route['name'])) {
        $headers[] = ['key' => 'Idempotency-Key', 'value' => 'demo-' . $route['name'] . '-0001'];
    }
    $url = '{{base_url}}' . preg_replace_callback(
        '#\{([A-Za-z]+)\}#',
        static fn (array $match): string => '{{' . variableFor($match[1], $route['path']) . '}}',
        $route['path']
    );
    $request = [
        'method' => $route['method'],
        'header' => $headers,
        'url' => $url,
        'description' => $route['summary'] . ' Permission: ' . $route['permission'] . '.',
    ];
    if (in_array($route['method'], ['POST', 'PUT'], true)) {
        $request['body'] = [
            'mode' => 'raw',
            'raw' => json_encode(exampleBody($route['name']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    return [
        'name' => $route['method'] . ' ' . $route['path'],
        'request' => $request,
        'event' => [[
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    'pm.test("JSON object", function () {',
                    '  pm.response.to.be.json;',
                    '  pm.expect(pm.response.json()).to.be.an("object");',
                    '});',
                    'pm.test("success data or error envelope", function () {',
                    '  const body = pm.response.json();',
                    '  pm.expect(body.data !== undefined || (body.error && body.error.code)).to.be.ok;',
                    '});',
                ],
            ],
        ]],
    ];
}

/**
 * @param array{name: string, roles: list<string>} $route
 */
function authToken(array $route): string
{
    if ($route['name'] === 'logout') {
        return 'logout_token';
    }
    $roles = $route['roles'];
    if ($roles === ['admin']) {
        return 'admin_token';
    }
    if (in_array('vendor', $roles, true) && !in_array('shopper', $roles, true)) {
        return 'vendor_token';
    }

    return 'shopper_token';
}

function variableFor(string $parameter, string $path): string
{
    if ($parameter === 'offerId') {
        return 'offer_id';
    }
    if (str_contains($path, '/payments/')) {
        return 'payment_id';
    }
    if (str_contains($path, '/payouts/')) {
        return 'payout_id';
    }
    if (str_contains($path, '/invoices/')) {
        return 'invoice_id';
    }
    if (str_contains($path, '/vendors/')) {
        return 'vendor_id';
    }
    if (str_contains($path, '/orders/')) {
        return 'order_id';
    }

    return 'id';
}

/**
 * @return array<string, mixed>
 */
function environment(string $name, string $baseUrl): array
{
    return [
        'name' => $name,
        'values' => environmentValues($baseUrl),
        '_postman_variable_scope' => 'environment',
    ];
}

/**
 * @return list<array{key: string, value: string, enabled: bool}>
 */
function environmentValues(string $baseUrl): array
{
    $values = [
        'base_url' => $baseUrl,
        'token' => '',
        'admin_token' => '',
        'vendor_token' => '',
        'shopper_token' => '',
        'logout_token' => '',
        'vendor_id' => '1',
        'order_id' => '1',
        'vendor_order_id' => '1',
        'payment_id' => '1',
        'offer_id' => '1',
        'invoice_id' => '1',
        'payout_id' => '1',
        'id' => '1',
    ];
    $items = [];
    foreach ($values as $key => $value) {
        $items[] = ['key' => $key, 'value' => $value, 'enabled' => true];
    }

    return $items;
}

function write(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create ' . $directory);
    }
    file_put_contents($path, $contents);
}

function yaml(mixed $value, int $indent = 0): string
{
    if (!is_array($value)) {
        return scalar($value) . "\n";
    }
    $isList = array_is_list($value);
    $pad = str_repeat('  ', $indent);
    $out = '';
    foreach ($value as $key => $item) {
        if ($isList) {
            if (is_array($item)) {
                $out .= $pad . "-\n" . yaml($item, $indent + 1);
            } else {
                $out .= $pad . '- ' . scalar($item) . "\n";
            }
            continue;
        }
        if (is_array($item)) {
            if ($item === []) {
                $out .= $pad . $key . ": {}\n";
                continue;
            }
            $out .= $pad . $key . ":\n" . yaml($item, $indent + 1);
            continue;
        }
        $out .= $pad . $key . ': ' . scalar($item) . "\n";
    }

    return $out;
}

function scalar(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    $text = (string) $value;
    if ($text === '' || preg_match('/[:#{}\\[\\],&*!|>%@`\'"\\n]|^(true|false|null|yes|no)$/i', $text) || is_numeric($text)) {
        return json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return $text;
}
