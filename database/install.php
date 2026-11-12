<?php
// Установка БД: php database/install.php [--demo] [--force]
//   --force  пересоздать базу (удаляет все данные!)
//   --demo   наполнить демонстрационными данными (database/demo.php)
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

$args = $argv ?? [];
$demo = in_array('--demo', $args, true);
$force = in_array('--force', $args, true);
$c = cfg('db');

$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = (bool)$pdo->query("SHOW DATABASES LIKE '" . $c['name'] . "'")->fetchColumn();
if ($exists && !$force) {
    fwrite(STDERR, "База {$c['name']} уже существует. Запустите с --force, чтобы пересоздать.\n");
    exit(1);
}
if ($exists) {
    $pdo->exec('DROP DATABASE `' . $c['name'] . '`');
}
$pdo->exec('CREATE DATABASE `' . $c['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `' . $c['name'] . '`');
$pdo->exec("SET NAMES utf8mb4, time_zone = '+03:00'");

/** Выполняет SQL-файл: операторы разделены ";" в конце строки либо строкой "--#" (для триггеров). */
function run_script(PDO $pdo, string $file, string $sep): void
{
    $sql = file_get_contents($file);
    $parts = $sep === ';' ? preg_split('/;\s*\n/', $sql) : preg_split('/^--#\s*$/m', $sql);
    foreach ($parts as $p) {
        $p = trim(preg_replace('/^\s*--.*$/m', '', $p));
        if ($p !== '') {
            $pdo->exec($p);
        }
    }
}

run_script($pdo, __DIR__ . '/schema.sql', ';');
echo "Схема создана\n";
run_script($pdo, __DIR__ . '/triggers.sql', '--#');
echo "Триггеры созданы\n";
run_script($pdo, __DIR__ . '/views.sql', ';');
echo "Представления созданы\n";
run_script($pdo, __DIR__ . '/seed.sql', ';');
echo "Справочники и настройки заполнены\n";

foreach (['photos', 'mails', 'sms', 'logs', 'backups'] as $d) {
    if (!is_dir(STORAGE . '/' . $d)) {
        mkdir(STORAGE . '/' . $d, 0775, true);
    }
}

if ($demo) {
    require __DIR__ . '/demo.php';
} else {
    // без демо-данных создаётся только учётная запись администратора
    $now = date('Y-m-d H:i:s');
    $eid = Db::insert('employees', [
        'last_name' => 'Громов', 'first_name' => 'Константин', 'middle_name' => 'Сергеевич',
        'position_id' => (int)Db::val("SELECT id FROM positions WHERE name = 'Системный администратор'"),
        'status_id' => (int)Db::val("SELECT id FROM employee_statuses WHERE code = 'active'"),
        'schedule_type_id' => (int)Db::val("SELECT id FROM schedule_types WHERE code = 'five_day'"),
        'phone' => '+7 (496) 500-10-03', 'email' => 'gromov@posaddom.example', 'hire_date' => date('Y-m-d'), 'created_at' => $now,
    ]);
    Db::insert('users', [
        'login' => 'gromov.ks', 'password_hash' => Auth::hashPassword('Admin12345'),
        'role_id' => (int)Db::val("SELECT id FROM roles WHERE code = 'admin'"), 'employee_id' => $eid,
        'email' => 'gromov@posaddom.example', 'must_change_password' => 1, 'created_at' => $now,
    ]);
    echo "Администратор: gromov.ks / Admin12345 (смените пароль после первого входа)\n";
}
echo "Готово\n";
