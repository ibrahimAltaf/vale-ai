<?php

declare(strict_types=1);

namespace Marketplace\Platform;

use Marketplace\Orders\Adapter\PrestaShopOrderGateway;

/**
 * Runs only inside a booted PrestaShop shop. PHPUnit does not construct it.
 */
final class PrestaShopNativeOrders implements NativeOrderPort
{
    public function createForPaidOrder(string $reference, int $customerId): array
    {
        $created = (new PrestaShopOrderGateway())->create($reference, [
            'id_customer' => $customerId,
            'payment_method' => 'card',
        ]);
        $rows = [];
        foreach ($created as $order) {
            $rows[] = [
                'vendor_reference' => (string) $order['reference'],
                'id_order' => (int) $order['id_order'],
            ];
        }

        return $rows;
    }
}
