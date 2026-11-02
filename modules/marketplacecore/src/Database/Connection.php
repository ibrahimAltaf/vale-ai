<?php

declare(strict_types=1);

namespace Marketplace\Core\Database;

final class Connection
{
    public static function table(string $suffix): string
    {
        $prefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'ps_';

        return '`' . $prefix . $suffix . '`';
    }

    public static function quote(string $value): string
    {
        return '\'' . pSQL($value, true) . '\'';
    }

    public function all(string $sql): array
    {
        $rows = \Db::getInstance()->executeS($sql, true, false);

        return is_array($rows) ? $rows : [];
    }

    public function one(string $sql): ?array
    {
        $row = \Db::getInstance()->getRow($sql, false);

        return is_array($row) ? $row : null;
    }

    public function value(string $sql): string
    {
        $value = \Db::getInstance()->getValue($sql, false);

        return $value === false || $value === null ? '' : (string) $value;
    }

    public function exec(string $sql): void
    {
        $ok = \Db::getInstance()->execute($sql);
        if ($ok === false) {
            throw new \RuntimeException(\Db::getInstance()->getMsgError());
        }
    }

    public function lastId(): int
    {
        return (int) \Db::getInstance()->Insert_ID();
    }

    public function transaction(callable $callback): mixed
    {
        $db = \Db::getInstance();
        if ($db->execute('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start a database transaction.');
        }

        try {
            $result = $callback($this);
            if ($db->execute('COMMIT') === false) {
                throw new \RuntimeException('Unable to commit the database transaction.');
            }

            return $result;
        } catch (\Throwable $exception) {
            $db->execute('ROLLBACK');
            throw $exception;
        }
    }
}
