<?php

declare(strict_types=1);

namespace Marketplace\Platform;

use PDO;
use PDOException;

/**
 * Idempotent schema for MySQL shops and SQLite tests.
 * Statements are CREATE IF NOT EXISTS or additive ALTER statements.
 */
final class Schema
{
    public static function apply(PDO $pdo, string $prefix): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (self::statements($driver, $prefix) as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $exception) {
                if (!self::alreadyApplied($exception)) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function mysqlStatements(string $prefix): array
    {
        return self::statements('mysql', $prefix);
    }

    /**
     * @return list<string>
     */
    public static function statements(string $driver, string $prefix): array
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            throw new \InvalidArgumentException('Database prefix contains unsupported characters.');
        }

        $file = $driver === 'sqlite' ? 'sqlite.sql' : 'mysql.sql';
        $sql = (string) file_get_contents(dirname(__DIR__) . '/sql/' . $file);
        $sql = str_replace('PREFIX_', $prefix, $sql);
        $statements = [];
        foreach (preg_split('/;\s*[\r\n]+/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private static function alreadyApplied(PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'already exists');
    }
}
