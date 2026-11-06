<?php

declare(strict_types=1);

namespace Marketplace\Platform\Mangopay;

use Marketplace\Platform\PlatformException;

/**
 * Rejects raw card data before it can reach a provider or a database.
 */
final class CardPayload
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function reject(array $payload): void
    {
        $encoded = json_encode($payload);
        if (!is_string($encoded)) {
            throw new PlatformException('validation_error', 'Payment payload could not be read.', 422);
        }
        if (preg_match('/"(cardnumber|card_number|cvv|cvc|pan|securitycode)"/i', $encoded)) {
            throw new PlatformException('card_data_rejected', 'Raw card numbers and security codes are not accepted.', 422);
        }
    }
}
