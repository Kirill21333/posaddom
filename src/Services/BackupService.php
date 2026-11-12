<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Резервное копирование БД: mysqldump из XAMPP, при его отсутствии - дамп средствами PHP. */
final class BackupService
{
    public static function dir(): string
    {
        $d = STORAGE . '/backups';
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    /** Создаёт копию и возвращает имя файла. */
    public static function create(): string
    {
        return self::createDetailed()['name'];
    }

    /** @return array{name:string,size:int,method:string,created_at:string} */
    public static function createDetailed(): array
    {
        $name = 'backup_' . cfg('db.name', 'posaddom') . '_' . date('Ymd_His') . '.sql';
        $path = self::dir() . '/' . $name;
        $method = 'mysqldump';
        $err = null;
        $bin = self::findDump();
        if ($bin !== null) {
            $err = self::runDump($bin, $path);
        } else {
            $err = 'mysqldump не найден';
        }
        if ($err !== null || !is_file($path) || filesize($path) < 200) {
            @unlink($path);
            self::phpDump($path);
            $method = 'php';
        }
        return ['name' => $name, 'size' => (int)filesize($path), 'method' => $method, 'created_at' => date('Y-m-d H:i:s', (int)filemtime($path))];
    }

    /** @return array<int,array{name:string,size:int,created_at:string}> */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/backup_*.sql') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'created_at' => date('Y-m-d H:i:s', (int)filemtime($f))];
        }
        usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    public static function path(string $name): ?string
    {
        if (!preg_match('/^backup_[A-Za-z0-9_]+\.sql$/', $name)) {
            return null;
        }
        $p = self::dir() . '/' . $name;
        return is_file($p) ? $p : null;
    }

    private static function findDump(): ?string
    {
        foreach (['C:\\xampp\\mysql\\bin\\mysqldump.exe', 'C:/xampp/mysql/bin/mysqldump.exe', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump'] as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        $which = PHP_OS_FAMILY === 'Windows' ? 'where mysqldump 2>NUL' : 'command -v mysqldump 2>/dev/null';
        $out = @shell_exec($which);
        $line = $out ? trim(strtok($out, "\r\n")) : '';
        return $line !== '' && is_file($line) ? $line : null;
    }

    /** @return string|null текст ошибки или null при успехе */
    private static function runDump(string $bin, string $path): ?string
    {
        $c = cfg('db');
        $cmd = [$bin, '--host=' . $c['host'], '--port=' . $c['port'], '--user=' . $c['user']];
        if (($c['pass'] ?? '') !== '') {
            $cmd[] = '--password=' . $c['pass'];
        }
        array_push($cmd, '--default-character-set=utf8mb4', '--single-transaction', '--routines', '--triggers', '--result-file=' . $path, $c['name']);
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return 'не удалось запустить mysqldump';
        }
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return $code === 0 ? null : trim((string)$stderr);
    }

    private static function phpDump(string $path): void
    {
        $pdo = Db::pdo();
        $fh = fopen($path, 'wb');
        fwrite($fh, '-- ПосадДом: резервная копия от ' . date('d.m.Y H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = array_column($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM), 0);
        foreach ($tables as $t) {
            $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(\PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
            $st = $pdo->query("SELECT * FROM `$t`", \PDO::FETCH_NUM);
            $batch = [];
            while ($row = $st->fetch()) {
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
                if (count($batch) >= 200) {
                    fwrite($fh, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($fh, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
            }
            fwrite($fh, "\n");
        }
        $views = array_column($pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(\PDO::FETCH_NUM), 0);
        foreach ($views as $v) {
            $create = $pdo->query("SHOW CREATE VIEW `$v`")->fetch(\PDO::FETCH_NUM)[1];
            $create = preg_replace('/^CREATE\s.*?VIEW/is', 'CREATE VIEW', $create);
            fwrite($fh, "DROP VIEW IF EXISTS `$v`;\n$create;\n\n");
        }
        fwrite($fh, "DELIMITER $$\n");
        foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(\PDO::FETCH_ASSOC) as $tr) {
            $create = $pdo->query('SHOW CREATE TRIGGER `' . $tr['Trigger'] . '`')->fetch(\PDO::FETCH_NUM)[2] ?? null;
            if ($create) {
                $create = preg_replace('/^CREATE\s.*?TRIGGER/is', 'CREATE TRIGGER', $create);
                fwrite($fh, 'DROP TRIGGER IF EXISTS `' . $tr['Trigger'] . "`$$\n$create$$\n");
            }
        }
        fwrite($fh, "DELIMITER ;\nSET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fh);
    }
}
