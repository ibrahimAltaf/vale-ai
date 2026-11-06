<?php

declare(strict_types=1);

namespace Marketplace\Platform\Mangopay;

use Marketplace\Platform\PlatformException;

/**
 * HTTPS client for the Mangopay API. Redirects are not followed.
 */
final class CurlTransport implements HttpTransport
{
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new PlatformException('mangopay_unavailable', 'The payment provider HTTP client is not available.', 503);
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new PlatformException('mangopay_unavailable', 'The payment provider request could not be started.', 503);
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            curl_close($handle);
            throw new PlatformException('mangopay_unavailable', 'The payment provider could not be reached.', 502);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => (string) $raw];
    }
}
