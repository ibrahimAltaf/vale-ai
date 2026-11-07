<?php

declare(strict_types=1);

namespace Marketplace\Platform;

/**
 * Creates one native shop order per vendor shipment after a marketplace payment.
 * The API never copies a shopper id into id_customer. The port receives the linked
 * PrestaShop customer id only.
 */
interface NativeOrderPort
{
    /**
     * @return list<array{vendor_reference: string, id_order: int}>
     */
    public function createForPaidOrder(string $reference, int $customerId): array;
}
