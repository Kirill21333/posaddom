<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Raw;
use App\Core\Request;
use App\Services\BackupService;
use App\Services\Part2Util as U;

/** Администрирование: пользователи, настройки, правила автоназначения, журнал аудита, резервная копия, очередь интеграций. */
final class AdminController
{
    // ------------------------------------------------------------------ пользователи

    private const USER_SELECT = "SELECT u.id, u.login, u.email, u.role_id, ro.code AS role_code, ro.name AS role_name, u.employee_id,
            u.is_active, u.failed_attempts, u.locked_until, u.last_login_at, u.must_change_password, u.created_at,
            e.last_name, e.first_name, e.middle_name, pos.name AS position_name
        FROM users u JOIN roles ro ON ro.id = u.role_id
        LEFT JOIN employees e ON e.id = u.employee_id
        LEFT JOIN positions pos ON pos.id = e.position_id";

    public static function users(Request $r): array
    {
        $where = [];
        $params = [];
        $q = $r->q('q');
        if ($q !== null) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where[] = "(u.login LIKE ? OR CONCAT_WS(' ', e.last_name, e.first_name, e.middle_name) LIKE ?)";
            array_push($params, $like, $like);
        }
        $role = $r->q('role');
        if ($role !== null) {
            $where[] = 'ro.code = ?';
            $params[] = $role;
        }
        if ($r->q('active') === '0' || $r->q('active') === '1') {
            $where[] = 'u.is_active = ?';
            $params[] = (int)$r->q('active');
        }
        $rows = Db::all(self::USER_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY u.login', $params);
        return ['items' => array_map([self::class, 'userShape'], $rows), 'total' => count($rows)];
    }

    public static function createUser(Request $r): array
    {
        $login = trim((string)$r->str('login', ''));
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $login)) {
            throw HttpException::bad('Логин: 3-50 символов (латиница, цифры, точка, дефис, подчёркивание)', ['login' => 'Некорректный логин']);
        }
        if (Db::val('SELECT id FROM users WHERE login = ?', [$login])) {
            throw HttpException::conflict('Пользователь с логином «' . $login . '» уже существует');
        }
        $pw = (string)($r->body['password'] ?? '');
        self::checkPassword($pw);
        $roleId = self::roleId($r);
        $roleCode = (string)Db::val('SELECT code FROM roles WHERE id = ?', [$roleId]);
        $eid = self::employeeInput($r, null);
        if ($roleCode === 'employee' && !$eid) {
            throw HttpException::bad('Для роли «Сотрудник» нужно выбрать карточку сотрудника', ['employee_id' => 'Выберите сотрудника']);
        }
        $email = self::emailInput($r->body['email'] ?? null);
        $id = Db::insert('users', [
            'login' => $login, 'password_hash' => password_hash($pw, PASSWORD_BCRYPT), 'role_id' => $roleId, 'employee_id' => $eid,
            'email' => $email, 'is_active' => array_key_exists('is_active', $r->body) ? U::flag($r->body['is_active']) : 1,
            'must_change_password' => U::flag($r->body['must_change_password'] ?? 0), 'created_at' => now(),
        ]);
        U::audit('user.create', 'user', $id, null, ['login' => $login, 'role' => $roleCode, 'employee_id' => $eid]);
        return self::userById($id);
    }

    public static function updateUser(Request $r, array $p): array
    {
        $id = (int)$p['id'];
        $old = Db::one(self::USER_SELECT . ' WHERE u.id = ?', [$id]);
        if (!$old) {
            throw HttpException::notFound('Пользователь не найден');
        }
        $me = U::me();
        $new = [];
        if (isset($r->body['login'])) {
            $login = trim((string)$r->body['login']);
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $login)) {
                throw HttpException::bad('Логин: 3-50 символов (латиница, цифры, точка, дефис, подчёркивание)');
            }
            if ($login !== $old['login']) {
                if (Db::val('SELECT id FROM users WHERE login = ? AND id <> ?', [$login, $id])) {
                    throw HttpException::conflict('Пользователь с логином «' . $login . '» уже существует');
                }
                $new['login'] = $login;
            }
        }
        if (array_key_exists('role', $r->body) || array_key_exists('role_id', $r->body)) {
            $rid = self::roleId($r);
            if ($rid !== (int)$old['role_id']) {
                $new['role_id'] = $rid;
            }
        }
        $roleCode = isset($new['role_id']) ? (string)Db::val('SELECT code FROM roles WHERE id = ?', [$new['role_id']]) : $old['role_code'];
        $eid = $old['employee_id'] === null ? null : (int)$old['employee_id'];
        if (array_key_exists('employee_id', $r->body)) {
            $eid = self::employeeInput($r, $id);
            if ($eid !== ($old['employee_id'] === null ? null : (int)$old['employee_id'])) {
                $new['employee_id'] = $eid;
            }
        }
        if ($roleCode === 'employee' && !$eid) {
            throw HttpException::bad('Для роли «Сотрудник» нужно выбрать карточку сотрудника');
        }
        if (array_key_exists('email', $r->body)) {
            $email = self::emailInput($r->body['email']);
            if ($email !== $old['email']) {
                $new['email'] = $email;
            }
        }
        if (array_key_exists('is_active', $r->body)) {
            $a = U::flag($r->body['is_active']);
            if ($a !== (int)$old['is_active']) {
                $new['is_active'] = $a;
            }
        }
        if (array_key_exists('must_change_password', $r->body)) {
            $m = U::flag($r->body['must_change_password']);
            if ($m !== (int)$old['must_change_password']) {
                $new['must_change_password'] = $m;
            }
        }
        if ($id === $me['id']) {
            if ((int)($new['is_active'] ?? 1) === 0) {
                throw HttpException::conflict('Нельзя отключить собственную учётную запись');
            }
            if (isset($new['role_id']) && $roleCode !== 'admin') {
                throw HttpException::conflict('Нельзя снять с себя роль администратора');
            }
        }
        if (isset($new['is_active']) && $new['is_active'] === 0 && $old['role_code'] === 'admin') {
            $others = (int)Db::val("SELECT COUNT(*) FROM users u JOIN roles ro ON ro.id = u.role_id WHERE ro.code = 'admin' AND u.is_active = 1 AND u.id <> ?", [$id]);
            if ($others === 0) {
                throw HttpException::conflict('Нельзя отключить последнего администратора');
            }
        }
        if (isset($r->body['password']) && (string)$r->body['password'] !== '') {
            self::checkPassword((string)$r->body['password']);
            $new['password_hash'] = password_hash((string)$r->body['password'], PASSWORD_BCRYPT);
        }
        if ($new) {
            Db::update('users', $new, 'id = ?', [$id]);
            $log = $new;
            if (isset($log['password_hash'])) {
                $log['password_hash'] = '(изменён)';
            }
            $before = [];
            foreach (array_keys($log) as $k) {
                $before[$k] = $k === 'password_hash' ? '(прежний)' : ($old[$k] ?? null);
            }
            U::audit('user.update', 'user', $id, $before, $log);
        }
        return self::userById($id);
    }

    public static function resetPassword(Request $r, array $p): array
    {
        $id = (int)$p['id'];
        if (!Db::val('SELECT id FROM users WHERE id = ?', [$id])) {
            throw HttpException::notFound('Пользователь не найден');
        }
        $pw = isset($r->body['password']) && (string)$r->body['password'] !== '' ? (string)$r->body['password'] : self::generatePassword();
        self::checkPassword($pw);
        Db::update('users', [
            'password_hash' => password_hash($pw, PASSWORD_BCRYPT), 'must_change_password' => 1, 'failed_attempts' => 0, 'locked_until' => null,
        ], 'id = ?', [$id]);
        U::audit('user.reset_password', 'user', $id, null, ['must_change_password' => 1]);
        return ['password' => $pw, 'must_change_password' => 1, 'user' => self::userById($id)];
    }

    public static function unlock(Request $r, array $p): array
    {
        $id = (int)$p['id'];
        if (!Db::val('SELECT id FROM users WHERE id = ?', [$id])) {
            throw HttpException::notFound('Пользователь не найден');
        }
        Db::update('users', ['failed_attempts' => 0, 'locked_until' => null], 'id = ?', [$id]);
        U::audit('user.unlock', 'user', $id, null, null);
        return self::userById($id);
    }

    // ------------------------------------------------------------------ настройки

    private const SETTING_RULES = [
        'business_start' => 'time', 'business_end' => 'time',
        'warn_percent' => [1, 99], 'login_max_attempts' => [1, 20], 'login_lock_minutes' => [1, 1440],
        'photo_retention_years' => [1, 50], 'default_max_load' => [1, 255], 'integrations_enabled' => 'bool', 'photo_max_mb' => [1, 50],
    ];

    public static function settings(Request $r): array
    {
        $rows = Db::all('SELECT `key`, `value`, description FROM settings ORDER BY `key`');
        return [
            'items' => array_map(fn($x) => ['key' => $x['key'], 'value' => $x['value'], 'description' => $x['description']], $rows),
            'values' => array_column($rows, 'value', 'key'),
        ];
    }

    /** PUT /settings: {settings:{key:value}} или {key:value,...} или {items:[{key,value}]}. */
    public static function saveSettings(Request $r): array
    {
        $in = $r->body['settings'] ?? null;
        if (!is_array($in) && isset($r->body['items']) && is_array($r->body['items'])) {
            $in = [];
            foreach ($r->body['items'] as $it) {
                if (is_array($it) && isset($it['key'])) {
                    $in[(string)$it['key']] = $it['value'] ?? '';
                }
            }
        }
        if (!is_array($in)) {
            $in = $r->body;
        }
        if (!$in) {
            throw HttpException::bad('Не переданы настройки');
        }
        $cur = array_column(Db::all('SELECT `key`, `value` FROM settings'), 'value', 'key');
        $changes = [];
        $errors = [];
        foreach ($in as $k => $v) {
            $k = (string)$k;
            if (!array_key_exists($k, $cur)) {
                $errors[$k] = "Неизвестная настройка: $k";
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            }
            $v = trim((string)$v);
            $rule = self::SETTING_RULES[$k] ?? null;
            if ($rule === 'time') {
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                    $errors[$k] = "$k: время в формате ЧЧ:ММ";
                    continue;
                }
            } elseif ($rule === 'bool') {
                if (!in_array($v, ['0', '1'], true)) {
                    $errors[$k] = "$k: допустимо 0 или 1";
                    continue;
                }
            } elseif (is_array($rule)) {
                if (!preg_match('/^\d+$/', $v) || (int)$v < $rule[0] || (int)$v > $rule[1]) {
                    $errors[$k] = "$k: целое число от {$rule[0]} до {$rule[1]}";
                    continue;
                }
                $v = (string)(int)$v;
            } elseif (mb_strlen($v) > 255) {
                $errors[$k] = "$k: не более 255 символов";
                continue;
            }
            if ($v !== $cur[$k]) {
                $changes[$k] = $v;
            }
        }
        if (isset($changes['business_start']) || isset($changes['business_end'])) {
            $s = $changes['business_start'] ?? $cur['business_start'];
            $e = $changes['business_end'] ?? $cur['business_end'];
            if ($e <= $s) {
                $errors['business_end'] = 'Окончание рабочего дня должно быть позже начала';
            }
        }
        if ($errors) {
            throw HttpException::bad(reset($errors), $errors);
        }
        Db::tx(function () use ($changes) {
            foreach ($changes as $k => $v) {
                Db::exec('UPDATE settings SET `value` = ? WHERE `key` = ?', [$v, $k]);
            }
        });
        foreach ($changes as $k => $v) {
            U::audit('settings.change', 'setting', null, [$k => $cur[$k]], [$k => $v]);
        }
        return self::settings($r);
    }

    // ------------------------------------------------------------------ правила автоназначения

    private const RULE_SELECT = "SELECT ar.id, ar.name, ar.request_category_id, rc.code AS rc_code, rc.name AS rc_name,
            ar.service_category_id, sc.name AS sc_name, ar.section_id, sec.name AS sec_name,
            ar.priority, ar.max_load, ar.allow_off_schedule, ar.is_active
        FROM assignment_rules ar
        LEFT JOIN request_categories rc ON rc.id = ar.request_category_id
        LEFT JOIN service_categories sc ON sc.id = ar.service_category_id
        LEFT JOIN sections sec ON sec.id = ar.section_id";

    public static function rules(Request $r): array
    {
        $rows = Db::all(self::RULE_SELECT . ' ORDER BY ar.priority, ar.id');
        return ['items' => array_map([self::class, 'ruleShape'], $rows), 'total' => count($rows), 'default_max_load' => (int)U::setting('default_max_load', '9')];
    }

    public static function createRule(Request $r): array
    {
        $d = self::ruleInput($r, null);
        $id = Db::insert('assignment_rules', $d);
        U::audit('rule.create', 'assignment_rule', $id, null, $d);
        return self::ruleById($id);
    }

    public static function updateRule(Request $r, array $p): array
    {
        $id = (int)$p['id'];
        $old = Db::one('SELECT * FROM assignment_rules WHERE id = ?', [$id]);
        if (!$old) {
            throw HttpException::notFound('Правило не найдено');
        }
        $d = self::ruleInput($r, $old);
        Db::update('assignment_rules', $d, 'id = ?', [$id]);
        $before = [];
        foreach ($d as $k => $_) {
            $before[$k] = $old[$k];
        }
        U::audit('rule.update', 'assignment_rule', $id, $before, $d);
        return self::ruleById($id);
    }

    public static function deleteRule(Request $r, array $p): array
    {
        $id = (int)$p['id'];
        $old = Db::one('SELECT * FROM assignment_rules WHERE id = ?', [$id]);
        if (!$old) {
            throw HttpException::notFound('Правило не найдено');
        }
        Db::exec('DELETE FROM assignment_rules WHERE id = ?', [$id]);
        U::audit('rule.delete', 'assignment_rule', $id, $old, null);
        return ['deleted' => true, 'id' => $id];
    }

    // ------------------------------------------------------------------ журнал аудита

    public static function audit(Request $r): array
    {
        $where = [];
        $params = [];
        $user = $r->q('user');
        if ($user !== null) {
            if (ctype_digit($user)) {
                $where[] = 'a.user_id = ?';
                $params[] = (int)$user;
            } else {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $user) . '%';
                $where[] = "(u.login LIKE ? OR CONCAT_WS(' ', e.last_name, e.first_name, e.middle_name) LIKE ?)";
                array_push($params, $like, $like);
            }
        }
        $action = $r->q('action');
        if ($action !== null) {
            $where[] = 'a.action LIKE ?';
            $params[] = str_replace(['%', '_'], ['\\%', '\\_'], $action) . '%';
        }
        $entity = $r->q('entity');
        if ($entity !== null) {
            $where[] = 'a.entity_type = ?';
            $params[] = $entity;
            if ($r->qint('entity_id')) {
                $where[] = 'a.entity_id = ?';
                $params[] = $r->qint('entity_id');
            }
        }
        if ($r->q('from')) {
            $where[] = 'a.created_at >= ?';
            $params[] = U::date($r->q('from'), 'Дата «с»') . ' 00:00:00';
        }
        if ($r->q('to')) {
            $where[] = 'a.created_at < ?';
            $params[] = date('Y-m-d', strtotime(U::date($r->q('to'), 'Дата «по»') . ' +1 day')) . ' 00:00:00';
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $from = ' FROM audit_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN employees e ON e.id = u.employee_id';
        $total = (int)Db::val('SELECT COUNT(*)' . $from . $w, $params);
        [$page, $per, $off] = U::paging($r, 50, 200);
        $rows = Db::all('SELECT a.id, a.user_id, u.login, e.last_name, e.first_name, e.middle_name, a.action, a.entity_type, a.entity_id, a.old_value, a.new_value, a.ip_address, a.created_at'
            . $from . $w . " ORDER BY a.id DESC LIMIT $per OFFSET $off", $params);
        $items = [];
        foreach ($rows as $x) {
            $items[] = [
                'id' => (int)$x['id'], 'created_at' => $x['created_at'],
                'user' => $x['user_id'] ? ['id' => (int)$x['user_id'], 'login' => $x['login'], 'name' => $x['last_name'] !== null ? U::shortName($x) : $x['login']] : null,
                'user_name' => $x['user_id'] ? ($x['last_name'] !== null ? U::shortName($x) : $x['login']) : 'система',
                'action' => $x['action'], 'entity_type' => $x['entity_type'], 'entity_id' => $x['entity_id'] === null ? null : (int)$x['entity_id'],
                'old_value' => $x['old_value'] === null ? null : mb_substr($x['old_value'], 0, 1000),
                'new_value' => $x['new_value'] === null ? null : mb_substr($x['new_value'], 0, 1000),
                'ip_address' => $x['ip_address'],
            ];
        }
        return [
            'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per)),
            'actions' => array_column(Db::all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action'),
        ];
    }

    // ------------------------------------------------------------------ резервная копия

    public static function backup(Request $r): array
    {
        $res = BackupService::createDetailed();
        U::audit('backup.create', 'backup', null, null, $res);
        return $res;
    }

    public static function backups(Request $r): array
    {
        return ['items' => BackupService::list()];
    }

    public static function downloadBackup(Request $r, array $p)
    {
        $path = BackupService::path((string)$p['name']);
        if ($path === null) {
            throw HttpException::notFound('Резервная копия не найдена');
        }
        U::audit('backup.download', 'backup', null, null, ['name' => basename($path)]);
        return new Raw('application/sql; charset=utf-8', '', basename($path), $path);
    }

    // ------------------------------------------------------------------ очередь интеграций

    public static function queue(Request $r): array
    {
        $where = [];
        $params = [];
        foreach (['status' => 'q.status', 'system' => 'q.system', 'direction' => 'q.direction'] as $k => $col) {
            $v = $r->q($k);
            if ($v !== null) {
                $where[] = "$col = ?";
                $params[] = $v;
            }
        }
        if ($r->qint('request')) {
            $where[] = 'q.request_id = ?';
            $params[] = $r->qint('request');
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int)Db::val('SELECT COUNT(*) FROM integration_queue q' . $w, $params);
        [$page, $per, $off] = U::paging($r, 50, 200);
        $rows = Db::all('SELECT q.id, q.system, q.request_id, rq.request_number, q.direction, q.payload, q.status, q.attempts, q.last_error, q.next_try_at, q.created_at, q.processed_at
            FROM integration_queue q LEFT JOIN requests rq ON rq.id = q.request_id' . $w . " ORDER BY q.id DESC LIMIT $per OFFSET $off", $params);
        $items = array_map(fn($x) => [
            'id' => (int)$x['id'], 'system' => $x['system'], 'direction' => $x['direction'],
            'request' => $x['request_id'] ? ['id' => (int)$x['request_id'], 'number' => $x['request_number']] : null,
            'request_id' => $x['request_id'] === null ? null : (int)$x['request_id'], 'request_number' => $x['request_number'],
            'payload' => mb_substr((string)$x['payload'], 0, 500), 'status' => $x['status'], 'attempts' => (int)$x['attempts'],
            'last_error' => $x['last_error'], 'next_try_at' => $x['next_try_at'], 'created_at' => $x['created_at'], 'processed_at' => $x['processed_at'],
        ], $rows);
        $counts = ['pending' => 0, 'sent' => 0, 'error' => 0];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM integration_queue GROUP BY status') as $c) {
            $counts[$c['status']] = (int)$c['n'];
        }
        return [
            'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'counts' => $counts,
            'integrations_enabled' => (int)U::setting('integrations_enabled', '1'),
        ];
    }

    public static function retry(Request $r, array $p): array
    {
        $id = (int)$p['id'];
        $row = Db::one('SELECT id, status FROM integration_queue WHERE id = ?', [$id]);
        if (!$row) {
            throw HttpException::notFound('Сообщение очереди не найдено');
        }
        if ($row['status'] === 'sent') {
            throw HttpException::conflict('Сообщение уже отправлено');
        }
        Db::update('integration_queue', ['status' => 'pending', 'attempts' => 0, 'next_try_at' => now(), 'last_error' => null], 'id = ?', [$id]);
        U::audit('integration.retry', 'integration_queue', $id, ['status' => $row['status']], ['status' => 'pending']);
        return ['id' => $id, 'status' => 'pending'];
    }

    // ------------------------------------------------------------------ внутреннее

    private static function userById(int $id): array
    {
        return self::userShape(Db::one(self::USER_SELECT . ' WHERE u.id = ?', [$id]));
    }

    private static function userShape(array $u): array
    {
        $locked = $u['locked_until'] !== null && $u['locked_until'] > now();
        return [
            'id' => (int)$u['id'], 'login' => $u['login'], 'email' => $u['email'],
            'role' => $u['role_code'], 'role_id' => (int)$u['role_id'], 'role_name' => $u['role_name'],
            'employee_id' => $u['employee_id'] === null ? null : (int)$u['employee_id'],
            'employee' => $u['employee_id'] ? ['id' => (int)$u['employee_id'], 'full_name' => U::fullName($u), 'short_name' => U::shortName($u), 'position' => $u['position_name']] : null,
            'name' => $u['employee_id'] ? U::shortName($u) : $u['login'],
            'is_active' => (int)$u['is_active'], 'failed_attempts' => (int)$u['failed_attempts'],
            'locked_until' => $u['locked_until'], 'is_locked' => $locked ? 1 : 0,
            'last_login_at' => $u['last_login_at'], 'must_change_password' => (int)$u['must_change_password'], 'created_at' => $u['created_at'],
        ];
    }

    private static function checkPassword(string $pw): void
    {
        $min = (int)cfg('security.password_min_len', 8);
        if (mb_strlen($pw) < $min || !preg_match('/[A-Za-zА-Яа-я]/u', $pw) || !preg_match('/\d/', $pw)) {
            throw HttpException::bad("Пароль: не менее $min символов, буквы и цифры", ['password' => 'Слишком простой пароль']);
        }
    }

    private static function generatePassword(): string
    {
        $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $s = '';
        for ($i = 0; $i < 8; $i++) {
            $s .= $abc[random_int(0, strlen($abc) - 1)];
        }
        return $s . random_int(10, 99);
    }

    private static function roleId(Request $r): int
    {
        $code = $r->body['role'] ?? null;
        if (is_string($code) && $code !== '') {
            $id = Db::val('SELECT id FROM roles WHERE code = ?', [$code]);
        } else {
            $id = Db::val('SELECT id FROM roles WHERE id = ?', [(int)($r->body['role_id'] ?? 0)]);
        }
        if (!$id) {
            throw HttpException::bad('Выберите роль: dispatcher, employee, manager или admin', ['role' => 'Недопустимая роль']);
        }
        return (int)$id;
    }

    private static function employeeInput(Request $r, ?int $userId): ?int
    {
        $v = $r->body['employee_id'] ?? null;
        if ($v === null || $v === '' || (int)$v === 0) {
            return null;
        }
        $eid = (int)$v;
        if (!Db::val('SELECT id FROM employees WHERE id = ?', [$eid])) {
            throw HttpException::bad('Сотрудник не найден', ['employee_id' => 'Сотрудник не найден']);
        }
        if (Db::val('SELECT id FROM users WHERE employee_id = ? AND id <> ?', [$eid, $userId ?? 0])) {
            throw HttpException::conflict('У этого сотрудника уже есть учётная запись');
        }
        return $eid;
    }

    private static function emailInput($v): ?string
    {
        $v = trim((string)($v ?? ''));
        if ($v === '') {
            return null;
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > 255) {
            throw HttpException::bad('Некорректный e-mail', ['email' => 'Некорректный e-mail']);
        }
        return $v;
    }

    private static function ruleShape(array $x): array
    {
        return [
            'id' => (int)$x['id'], 'name' => $x['name'],
            'request_category_id' => $x['request_category_id'] === null ? null : (int)$x['request_category_id'],
            'request_category' => $x['request_category_id'] ? ['id' => (int)$x['request_category_id'], 'code' => $x['rc_code'], 'name' => $x['rc_name']] : null,
            'service_category_id' => $x['service_category_id'] === null ? null : (int)$x['service_category_id'],
            'service_category' => $x['service_category_id'] ? ['id' => (int)$x['service_category_id'], 'name' => $x['sc_name']] : null,
            'section_id' => $x['section_id'] === null ? null : (int)$x['section_id'],
            'section' => $x['section_id'] ? ['id' => (int)$x['section_id'], 'name' => $x['sec_name']] : null,
            'priority' => (int)$x['priority'], 'max_load' => (int)$x['max_load'],
            'allow_off_schedule' => (int)$x['allow_off_schedule'], 'is_active' => (int)$x['is_active'],
        ];
    }

    private static function ruleById(int $id): array
    {
        return self::ruleShape(Db::one(self::RULE_SELECT . ' WHERE ar.id = ?', [$id]));
    }

    private static function ruleInput(Request $r, ?array $old): array
    {
        $in = static fn(string $k, $def = null) => array_key_exists($k, $r->body) ? $r->body[$k] : ($old[$k] ?? $def);
        $name = trim((string)$in('name', ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw HttpException::bad('Название правила: обязательное поле, не более 100 символов', ['name' => 'Укажите название']);
        }
        $rcId = $in('request_category_id');
        $code = $r->body['request_category'] ?? $r->body['request_category_code'] ?? null;
        if (is_string($code) && $code !== '') {
            $rcId = Db::val('SELECT id FROM request_categories WHERE code = ?', [$code]);
            if (!$rcId) {
                throw HttpException::bad('Категория заявки не найдена');
            }
        }
        $rcId = ($rcId === null || $rcId === '') ? null : (int)$rcId;
        if ($rcId !== null && !Db::val('SELECT id FROM request_categories WHERE id = ?', [$rcId])) {
            throw HttpException::bad('Категория заявки не найдена');
        }
        $scId = $in('service_category_id');
        $scId = ($scId === null || $scId === '') ? null : (int)$scId;
        if ($scId !== null && !Db::val('SELECT id FROM service_categories WHERE id = ?', [$scId])) {
            throw HttpException::bad('Категория услуг не найдена');
        }
        $secId = $in('section_id');
        $secId = ($secId === null || $secId === '') ? null : (int)$secId;
        if ($secId !== null && !Db::val('SELECT id FROM sections WHERE id = ?', [$secId])) {
            throw HttpException::bad('Участок не найден');
        }
        $prio = $in('priority', 10);
        if (!is_numeric($prio) || (int)$prio < 0 || (int)$prio > 255) {
            throw HttpException::bad('Приоритет: число от 0 до 255 (меньше - важнее)', ['priority' => 'Некорректный приоритет']);
        }
        $max = $in('max_load');
        if (!is_numeric($max) || (int)$max < 1 || (int)$max > 255) {
            throw HttpException::bad('Предельная нагрузка: число от 1 до 255', ['max_load' => 'Некорректная нагрузка']);
        }
        return [
            'name' => $name, 'request_category_id' => $rcId, 'service_category_id' => $scId, 'section_id' => $secId,
            'priority' => (int)$prio, 'max_load' => (int)$max,
            'allow_off_schedule' => U::flag($in('allow_off_schedule', 0)), 'is_active' => U::flag($in('is_active', 1)),
        ];
    }
}
