<?php

declare(strict_types=1);

/**
 * Proves MySQL raises SQLSTATE 40001 on a real deadlock.
 * Refuses the shop database and drops only the named throwaway database.
 */

$database = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_]+$/', $database) || $database === 'prestashop') {
    fwrite(STDERR, "Refusing the shop database.\n");
    exit(1);
}

$admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', 'prestashop', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$admin->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '`');
$admin->exec('GRANT ALL PRIVILEGES ON `' . $database . '`.* TO \'prestashop\'@\'%\'');
$admin->exec('FLUSH PRIVILEGES');
$pdo = static function () use ($database): PDO {
    return new PDO('mysql:host=db;dbname=' . $database . ';charset=utf8mb4', 'prestashop', 'prestashop', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
};

$setup = $pdo();
$setup->exec('DROP TABLE IF EXISTS deadlock_probe');
$setup->exec('CREATE TABLE deadlock_probe (id INT NOT NULL PRIMARY KEY, n INT NOT NULL) ENGINE=InnoDB');
$setup->exec('INSERT INTO deadlock_probe (id, n) VALUES (1, 0), (2, 0)');
unset($setup);

$childCode = <<<'PHP'
<?php
$pdo = new PDO('mysql:host=db;dbname=%s;charset=utf8mb4', 'prestashop', 'prestashop', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$pdo->exec('UPDATE deadlock_probe SET n = n + 1 WHERE id = 2');
file_put_contents('/tmp/deadlock-ready', '1');
usleep(1500000);
try {
    $pdo->exec('UPDATE deadlock_probe SET n = n + 1 WHERE id = 1');
    $pdo->commit();
    file_put_contents('/tmp/deadlock-child', 'ok');
} catch (PDOException $exception) {
    file_put_contents('/tmp/deadlock-child', (string) ($exception->errorInfo[0] ?? 'error'));
}
PHP;
file_put_contents('/tmp/deadlock-child.php', sprintf($childCode, $database));
@unlink('/tmp/deadlock-ready');
@unlink('/tmp/deadlock-child');
$process = proc_open('php /tmp/deadlock-child.php', [1 => ['file', '/tmp/deadlock-child.out', 'w'], 2 => ['file', '/tmp/deadlock-child.err', 'w']], $pipes);
$parent = $pdo();
$started = microtime(true);
while (!is_file('/tmp/deadlock-ready') && microtime(true) - $started < 5) {
    usleep(50000);
}
$parent->beginTransaction();
$parent->exec('UPDATE deadlock_probe SET n = n + 1 WHERE id = 1');
$parentState = 'ok';
try {
    $parent->exec('UPDATE deadlock_probe SET n = n + 1 WHERE id = 2');
    $parent->commit();
} catch (PDOException $exception) {
    $parentState = (string) ($exception->errorInfo[0] ?? 'error');
    if ($parent->inTransaction()) {
        $parent->rollBack();
    }
}
if (is_resource($process)) {
    proc_close($process);
}
$childState = is_file('/tmp/deadlock-child') ? trim((string) file_get_contents('/tmp/deadlock-child')) : 'missing';
$admin->exec('DROP DATABASE `' . $database . '`');
$states = [$parentState, $childState];
echo json_encode([
    'parent' => $parentState,
    'child' => $childState,
    'saw_40001' => in_array('40001', $states, true),
], JSON_UNESCAPED_SLASHES) . "\n";
exit(in_array('40001', $states, true) ? 0 : 1);
