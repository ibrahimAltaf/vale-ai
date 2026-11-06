<?php

declare(strict_types=1);

namespace Marketplace\Platform;

/**
 * Domain failure with a stable API error code and HTTP status.
 * Messages are safe to return to clients.
 */
final class PlatformException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
