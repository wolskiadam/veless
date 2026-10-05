<?php
declare(strict_types=1);
namespace Pase\Services;

use PDO;

/** Streaming logical snapshot. Does not load application config or execute business code. */
final class DatabaseSnapshot
{
    private static function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new \RuntimeException('Unsupported database identifier'); }
        return '`' . $name . '`';
    }

    public static function export(PDO $pdo, string $path): array
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true) || $pdo->inTransaction()) { throw new \RuntimeException('Unsupported snapshot connection'); }
        if ($driver === 'mysql') {
            if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND GENERATION_EXPRESSION <> ''")->fetchColumn()) {
                throw new \RuntimeException('Generated columns require a native database backup');
            }
            $tables = $pdo->query('SELECT TABLE_NAME, ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tables as $table) {
                if ($table['ENGINE'] !== 'InnoDB' || $table['TABLE_TYPE'] !== 'BASE TABLE') { throw new \RuntimeException('Backup requires InnoDB tables without views'); }
            }
            foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $table => $schema) {
                if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.$table WHERE $schema = DATABASE()")->fetchColumn()) {
                    throw new \RuntimeException('Custom triggers/routines/events require a native database backup');
                }
            }
            $names = array_column($tables, 'TABLE_NAME');
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        } else {
            if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('view','trigger')")->fetchColumn()) { throw new \RuntimeException('Views/triggers not supported'); }
            $names = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        }
        $out = fopen($path, 'xb');
        if (!$out) { throw new \RuntimeException('Cannot create database snapshot'); }
        chmod($path, 0600);
        $write = static function (array $record) use ($out): void {
            $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($out, $line) !== strlen($line)) { throw new \RuntimeException('Snapshot write failed'); }
        };
        $counts = [];
        try {
            $pdo->beginTransaction();
            $write(['format' => 'crm-db-v1', 'driver' => $driver]);
            foreach ($names as $name) {
                $quoted = self::identifier($name);
                if ($driver === 'mysql') {
                    $schema = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM)[1];
                } else {
                    $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
                    $stmt->execute([$name]); $schema = $stmt->fetchColumn();
                }
                $write(['table' => $name, 'schema' => $schema]);
                $counts[$name] = 0;
                if ($driver === 'mysql') { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); }
                $rows = $pdo->query('SELECT * FROM ' . $quoted);
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    $encoded = [];
                    foreach ($row as $key => $value) { $encoded[$key] = $value === null ? null : base64_encode((string) $value); }
                    $write(['row' => $encoded]); ++$counts[$name];
                }
                $rows->closeCursor();
                if ($driver === 'mysql') { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); }
                if ($driver === 'sqlite') {
                    $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name=? AND sql IS NOT NULL");
                    $stmt->execute([$name]);
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sql) { $write(['index' => $sql]); }
                }
            }
            $write(['end' => $counts]);
            $pdo->commit();
            if (!fflush($out)) { throw new \RuntimeException('Snapshot flush failed'); }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        } finally {
            fclose($out);
            if ($driver === 'mysql') { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); }
        }
        return ['driver' => $driver, 'tables' => $counts];
    }

    /** Only for a freshly created isolated database. Never point at a live application database. */
    public static function restore(PDO $pdo, string $path): array
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['sqlite', 'mysql'], true)) { throw new \RuntimeException('Unsupported restore driver'); }
        if ($driver === 'mysql' && !preg_match('/^crm_restore_[a-f0-9]{24}$/D', (string) $pdo->query('SELECT DATABASE()')->fetchColumn())) {
            throw new \RuntimeException('MySQL restore requires a newly generated crm_restore_<24 hex> test database');
        }
        $existing = $driver === 'mysql' ? $pdo->query('SHOW TABLES')->fetchAll()
            : $pdo->query("SELECT name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'")->fetchAll();
        if ($existing || $pdo->inTransaction()) { throw new \RuntimeException('Restore destination must be empty'); }
        $in = fopen($path, 'rb');
        if (!$in) { throw new \RuntimeException('Cannot read database snapshot'); }
        $header = json_decode((string) fgets($in), true, 512, JSON_THROW_ON_ERROR);
        if (($header['format'] ?? '') !== 'crm-db-v1' || ($header['driver'] ?? '') !== $driver) { fclose($in); throw new \RuntimeException('Snapshot format/driver mismatch'); }
        $counts = []; $table = null; $complete = false;
        $pdo->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS=0' : 'PRAGMA foreign_keys=OFF');
        try {
            while (($line = fgets($in)) !== false) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($complete) { throw new \RuntimeException('Trailing snapshot content'); }
                if (isset($record['table'])) {
                    $table = $record['table']; self::identifier($table);
                    if (isset($counts[$table]) || !preg_match('/^CREATE TABLE\s/i', $record['schema'])) { throw new \RuntimeException('Invalid snapshot schema'); }
                    $pdo->exec($record['schema']); $counts[$table] = 0;
                } elseif (isset($record['row']) && $table !== null) {
                    $values = [];
                    foreach ($record['row'] as $value) {
                        $decoded = $value === null ? null : base64_decode($value, true);
                        if ($decoded === false) { throw new \RuntimeException('Invalid row encoding'); }
                        $values[] = $decoded;
                    }
                    $columns = implode(',', array_map(self::identifier(...), array_keys($record['row'])));
                    $marks = implode(',', array_fill(0, count($values), '?'));
                    $pdo->prepare('INSERT INTO ' . self::identifier($table) . " ($columns) VALUES ($marks)")->execute($values);
                    ++$counts[$table];
                } elseif (isset($record['index']) && $driver === 'sqlite') {
                    if (!preg_match('/^CREATE (UNIQUE )?INDEX\s/i', $record['index'])) { throw new \RuntimeException('Invalid index'); }
                    $pdo->exec($record['index']);
                } elseif (isset($record['end'])) {
                    if ($record['end'] !== $counts) { throw new \RuntimeException('Row count mismatch'); }
                    $complete = true;
                } else { throw new \RuntimeException('Invalid snapshot record'); }
            }
            if (!$complete) { throw new \RuntimeException('Truncated database snapshot'); }
            foreach ($counts as $name => $count) {
                if ((int) $pdo->query('SELECT COUNT(*) FROM ' . self::identifier($name))->fetchColumn() !== $count) { throw new \RuntimeException('Restore count mismatch'); }
            }
            if ($driver === 'sqlite' && $pdo->query('PRAGMA foreign_key_check')->fetch()) { throw new \RuntimeException('Foreign key mismatch'); }
        } finally {
            fclose($in);
            $pdo->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON');
        }
        return $counts;
    }
}
