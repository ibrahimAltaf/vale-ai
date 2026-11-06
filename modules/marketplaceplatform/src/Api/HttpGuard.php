<?php

declare(strict_types=1);

namespace Marketplace\Platform\Api;

use Marketplace\Platform\PlatformException;

/**
 * HTTP boundary checks for the PrestaShop front controller.
 * ApiServer tests call the services directly and do not pass through this class.
 */
final class HttpGuard
{
    /**
     * @return array<string, string>
     */
    public static function headers(string $requestId): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'no-store',
            'X-Request-Id' => $requestId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function jsonBody(string $raw, string $contentType, string $method): array
    {
        if (!in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            return [];
        }
        if (trim($raw) === '') {
            return [];
        }
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($type !== 'application/json') {
            throw new PlatformException('unsupported_media_type', 'Send application/json.', 415);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new PlatformException('invalid_json', 'The request body is not valid JSON.', 400);
        }

        return $decoded;
    }
}
