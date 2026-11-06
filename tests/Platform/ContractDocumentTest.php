<?php

declare(strict_types=1);

namespace Marketplace\Tests\Platform;

use Marketplace\Platform\Api\RouteMap;
use PHPUnit\Framework\TestCase;

final class ContractDocumentTest extends TestCase
{
    public function testOpenApiAndPostmanCoverEveryRoute(): void
    {
        $root = dirname(__DIR__, 2);
        $yaml = (string) file_get_contents($root . '/docs/openapi.yaml');
        $collection = json_decode((string) file_get_contents($root . '/postman/PrestaShop_Marketplace_API.postman_collection.json'), true);
        $local = json_decode((string) file_get_contents($root . '/postman/PrestaShop_Local.postman_environment.json'), true);
        $staging = json_decode((string) file_get_contents($root . '/postman/PrestaShop_Staging.postman_environment.json'), true);
        self::assertIsArray($collection);
        self::assertIsArray($local);
        self::assertIsArray($staging);
        self::assertStringStartsWith('openapi: 3.0.3', $yaml);
        self::assertSame('https://staging.example.invalid', $staging['values'][0]['value']);

        $names = [];
        foreach ($collection['item'] as $folder) {
            foreach ($folder['item'] as $request) {
                $names[] = $request['name'];
                self::assertNotEmpty($request['event']);
            }
        }
        foreach (RouteMap::routes() as $route) {
            self::assertStringContainsString($route['path'] . ':', $yaml);
            self::assertStringContainsString(strtolower($route['method']) . ':', $yaml);
            self::assertContains($route['method'] . ' ' . $route['path'], $names);
        }
        self::assertCount(count(RouteMap::routes()), $names);
        $blob = $yaml . json_encode($collection) . json_encode($local) . json_encode($staging);
        self::assertStringNotContainsString('DemoAdmin123', $blob);
        self::assertStringNotContainsString('HarborDemo123', $blob);
        self::assertStringNotContainsString('4242424242424242', $blob);
    }
}
