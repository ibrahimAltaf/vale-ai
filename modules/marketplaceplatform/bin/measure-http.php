<?php

declare(strict_types=1);

$path = $argv[1] ?? '/api/v1/health';
$base = $argv[2] ?? 'http://127.0.0.1';
$headers = $argv[3] ?? '';
$samples = [];
$status = 0;
for ($i = 0; $i < 40; $i++) {
    $started = hrtime(true);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $headers,
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);
    $body = file_get_contents($base . $path, false, $context);
    $samples[] = (hrtime(true) - $started) / 1_000_000;
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }
    if (!is_string($body) || $body === '') {
        fwrite(STDERR, "empty response\n");
        exit(1);
    }
}
sort($samples);
$at = static function (array $values, float $percentile): float {
    $index = (int) ceil($percentile * count($values)) - 1;

    return $values[max(0, $index)];
};
echo json_encode([
    'path' => $path,
    'status' => $status,
    'n' => count($samples),
    'p50_ms' => round($at($samples, 0.50), 1),
    'p95_ms' => round($at($samples, 0.95), 1),
    'p99_ms' => round($at($samples, 0.99), 1),
], JSON_UNESCAPED_SLASHES) . "\n";
