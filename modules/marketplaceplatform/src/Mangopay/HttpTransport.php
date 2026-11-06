<?php

declare(strict_types=1);

namespace Marketplace\Platform\Mangopay;

interface HttpTransport
{
    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
