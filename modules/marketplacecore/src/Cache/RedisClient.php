<?php

declare(strict_types=1);

namespace Marketplace\Core\Cache;

/**
 * Minimal RESP client so optimizer responses can be cached without the phpredis extension.
 * A failed connection is treated as a cache miss.
 */
final class RedisClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 6379,
        private readonly float $timeout = 0.25,
        private readonly string $password = '',
    ) {
    }

    public static function fromEnvironment(): ?self
    {
        $host = getenv('REDIS_HOST');
        if (!is_string($host) || $host === '') {
            return null;
        }
        $password = getenv('REDIS_PASSWORD');

        return new self($host, (int) (getenv('REDIS_PORT') ?: 6379), 0.25, is_string($password) ? $password : '');
    }

    public function get(string $key): ?string
    {
        $value = $this->command(['GET', $key]);

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): bool
    {
        $response = $this->command(['SET', $key, $value, 'EX', (string) $ttlSeconds]);

        return $response === 'OK';
    }

    public function incr(string $key): int
    {
        $value = $this->command(['INCR', $key]);

        return is_int($value) ? $value : 0;
    }

    /**
     * @param list<string> $parts
     */
    private function command(array $parts): mixed
    {
        $socket = $this->connect();
        if ($socket === null) {
            return null;
        }

        $payload = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $payload .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }
        if (fwrite($socket, $payload) === false) {
            return null;
        }

        $prefix = fgetc($socket);
        if ($prefix === false) {
            return null;
        }
        $line = $this->readLine($socket);
        if ($prefix === '+' || $prefix === '-') {
            return $prefix === '-' ? null : $line;
        }
        if ($prefix === ':') {
            return (int) $line;
        }
        if ($prefix === '$') {
            $length = (int) $line;
            if ($length < 0) {
                return null;
            }
            $data = $length === 0 ? '' : (string) fread($socket, $length);
            $this->readLine($socket);

            return $data;
        }

        return null;
    }

    /** @return resource|null */
    private function connect()
    {
        if (is_resource($this->socket)) {
            return $this->socket;
        }
        $socket = @fsockopen($this->host, $this->port, $errno, $error, $this->timeout);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, 0, (int) ($this->timeout * 1000000));
        $this->socket = $socket;
        if ($this->password !== '') {
            $auth = $this->command(['AUTH', $this->password]);
            if ($auth !== 'OK') {
                $this->socket = null;
                fclose($socket);

                return null;
            }
        }

        return $this->socket;
    }

    /** @param resource $socket */
    private function readLine($socket): string
    {
        $line = fgets($socket);
        if ($line === false) {
            return '';
        }

        return rtrim($line, "\r\n");
    }
}
