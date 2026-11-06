<?php

declare(strict_types=1);

namespace Marketplace\Platform;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Prepared-statement access shared by the marketplace services.
 */
final class Store
{
    private int $transactionDepth = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix,
    ) {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            throw new \InvalidArgumentException('Database prefix contains unsupported characters.');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function t(string $table): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Unsupported table name.');
        }

        return $this->prefix . $table;
    }

    public function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $statement = $this->run($sql, $params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string, mixed> $params
     */
    public function value(string $sql, array $params = []): string
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false || $value === null ? '' : (string) $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function exec(string $sql, array $params = []): int
    {
        $statement = $this->run($sql, $params);
        $count = $statement->rowCount();
        if ($count === 0 && $this->driver() === 'sqlite') {
            $count = (int) $this->pdo->query('SELECT changes()')->fetchColumn();
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $this->write('INSERT INTO', $table, $data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insertIgnore(string $table, array $data): void
    {
        $verb = $this->driver() === 'sqlite' ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';
        $this->write($verb, $table, $data);
    }

    public function transaction(callable $callback, bool $immediate = false): mixed
    {
        if ($this->transactionDepth > 0) {
            return $callback();
        }

        $attempt = 0;
        while (true) {
            ++$this->transactionDepth;
            try {
                return $this->begin($callback, $immediate);
            } catch (PDOException $exception) {
                if ($attempt >= 2 || !$this->isDeadlock($exception)) {
                    throw $exception;
                }
                ++$attempt;
            } finally {
                --$this->transactionDepth;
            }
        }
    }

    private function begin(callable $callback, bool $immediate): mixed
    {
        if ($immediate && $this->driver() === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $result = $callback();
                $this->pdo->exec('COMMIT');

                return $result;
            } catch (\Throwable $exception) {
                $this->pdo->exec('ROLLBACK');
                throw $exception;
            }
        }

        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function isUniqueViolation(PDOException $exception): bool
    {
        return ($exception->errorInfo[0] ?? '') === '23000';
    }

    private function isDeadlock(PDOException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? '');
        $message = strtolower($exception->getMessage());

        return $state === '40001' || str_contains($message, 'deadlock');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $parameter = is_int($name) ? $name + 1 : ':' . ltrim((string) $name, ':');
            if ($value === null) {
                $statement->bindValue($parameter, null, PDO::PARAM_NULL);
            } elseif (is_int($value) || is_bool($value)) {
                $statement->bindValue($parameter, (int) $value, PDO::PARAM_INT);
            } else {
                $statement->bindValue($parameter, (string) $value, PDO::PARAM_STR);
            }
        }
        $statement->execute();

        return $statement;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $verb, string $table, array $data): void
    {
        $columns = [];
        $params = [];
        foreach ($data as $column => $value) {
            if (!preg_match('/^[a-z0-9_]+$/', (string) $column)) {
                throw new \InvalidArgumentException('Unsupported column name.');
            }
            $columns[] = (string) $column;
            $params[$column] = $value;
        }
        if ($columns === []) {
            throw new \InvalidArgumentException('Insert requires columns.');
        }

        $holders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $sql = $verb . ' ' . $this->t($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $holders) . ')';
        $this->run($sql, $params);
    }
}
