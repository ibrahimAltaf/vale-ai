<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Marketplace\Platform\Mangopay\MemoryGateway;
use Marketplace\Platform\Platform;
use Marketplace\Platform\PlatformException;

$platform = Platform::open($argv[1], new MemoryGateway());
try {
    $platform->checkout(
        ['role' => 'shopper', 'id' => (int) $argv[2]],
        '10115',
        [['product_id' => (int) $argv[3], 'quantity' => 1]],
    );
    echo "OK\n";
} catch (PlatformException $exception) {
    if (in_array($exception->errorCode, ['insufficient_stock', 'infeasible'], true)) {
        echo "NO\n";
        exit(0);
    }
    fwrite(STDERR, $exception->errorCode . ' ' . $exception->getMessage() . "\n");
    echo "ERR\n";
    exit(1);
}
