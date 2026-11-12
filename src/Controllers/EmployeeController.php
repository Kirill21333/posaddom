<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Part2Util as U;

/** Сотрудники: карточки, графики, специализация, привязка к домам, личный график и статистика. */
final class EmployeeController
{
    private const WEEKDAYS = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];
    private const SELECT = "SELECT e.id, e.last_name, e.first_name, e.middle_name, e.position_id, pos.name AS position_name, pos.is_executor,
            e.status_id, st.code AS status_code, st.name AS status_name,
            e.schedule_type_id, sch.code AS schedule_code, sch.name AS schedule_name,
            e.contractor_id, ctr.name AS contractor_name, e.phone, e.email, e.hire_date, e.dismissal_date, e.pd_consent_at, e.created_at,
            u.id AS user_id, u.login AS user_login, ro.code AS user_role, u.is_active AS user_active
        FROM employees e
        JOIN positions pos ON pos.id = e.position_id
        JOIN employee_statuses st ON st.id = e.status_id
        JOIN schedule_types sch ON sch.id = e.schedule_type_id
        LEFT JOIN contractors ctr ON ctr.id = e.contractor_id
        LEFT JOIN users u ON u.employee_id = e.id
        LEFT JOIN roles ro ON ro.id = u.role_id";

    // ------------------------------------------------------------------ список и карточка

    public static function list(Request $r): array
    {
        $where = [];
        $params = [];
        $q = $r->q('q');
        if ($q !== null) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where[] = "(CONCAT_WS(' ', e.last_name, e.first_name, e.middle_name) LIKE ? OR e.phone LIKE ?)";
            array_push($params, $like, $like);
        }
        $pos = $r->q('position');
        if ($pos !== null) {
            if (ctype_digit($pos)) {
                $where[] = 'e.position_id = ?';
                $params[] = (int)$pos;
            } else {
                $where[] = 'pos.name LIKE ?';
                $params[] = '%' . $pos . '%';
            }
        }
        $status = $r->q('status');
        if ($status !== null) {
            if (ctype_digit($status)) {
                $where[] = 'e.status_id = ?';
                $params[] = (int)$status;
            } else {
                $where[] = 'st.code = ?';
                $params[] = $status;
            }
        }
        if ($r->q('executor') === '1') {
            $where[] = 'pos.is_executor = 1';
        }
        $rows = Db::all(self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.last_name, e.first_name', $params);
        $loads = U::loads();
        $max = U::maxLoad();
        $svc = self::counts('employee_services');
        $hs = self::counts('employee_houses');
        $items = [];
        foreach ($rows as $row) {
            $it = self::shape($row);
            $id = $it['id'];
            $it['load'] = $loads[$id]['load'] ?? 0;
            $it['open_requests'] = $loads[$id]['open'] ?? 0;
            $it['max_load'] = $max;
            $it['services_count'] = $svc[$id] ?? 0;
            $it['houses_count'] = $hs[$id] ?? 0;
            $items[] = $it;
        }
        return ['items' => $items, 'total' => count($items)];
    }

    public static function get(Request $r, array $p): array
    {
        $id = (int)$p['id'];
        $me = U::me();
        if ($me['role'] === 'employee' && $me['employee_id'] !== $id) {
            throw HttpException::forbidden('Сотрудник может просматривать только свою карточку');
        }
        return self::card($id);
    }

    // ------------------------------------------------------------------ создание и правка (администратор)

    public static function create(Request $r): array
    {
        U::requireRoles(['admin']);
        $d = self::validate($r, null);
        $account = self::validateAccount($r);
        $id = Db::tx(function () use ($d, $r, $account) {
            $d['created_at'] = now();
            $id = Db::insert('employees', $d);
            if (isset($r->body['schedule']) || isset($r->body['days'])) {
                self::replaceSchedule($id, self::scheduleInput($r));
            } else {
                self::defaultSchedule($id, (int)$d['schedule_type_id']);
            }
            if (isset($r->body['service_ids']) && is_array($r->body['service_ids'])) {
                self::replaceLinks('employee_services', 'service_id', 'services', $id, $r->body['service_ids']);
            }
            if (isset($r->body['house_ids']) && is_array($r->body['house_ids'])) {
                self::replaceLinks('employee_houses', 'house_id', 'houses', $id, $r->body['house_ids']);
            }
            if ($account) {
                Db::insert('users', [
                    'login' => $account['login'], 'password_hash' => password_hash($account['password'], PASSWORD_BCRYPT),
                    'role_id' => $account['role_id'], 'employee_id' => $id, 'email' => $d['email'],
                    'is_active' => 1, 'must_change_password' => 0, 'created_at' => now(),
                ]);
            }
            return $id;
        });
        U::audit('employee.create', 'employee', $id, null, ['name' => U::fullName($d), 'position_id' => $d['position_id'], 'account' => $account['login'] ?? null]);
        return self::card($id);
    }

    public static function update(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $id = (int)$p['id'];
        $old = Db::one('SELECT * FROM employees WHERE id = ?', [$id]);
        if (!$old) {
            throw HttpException::notFound('Сотрудник не найден');
        }
        $d = self::validate($r, $old);
        $diff = [];
        foreach ($d as $k => $v) {
            if ((string)($old[$k] ?? '') !== (string)($v ?? '')) {
                $diff[$k] = [$old[$k], $v];
            }
        }
        Db::tx(function () use ($id, $d, $old) {
            Db::update('employees', $d, 'id = ?', [$id]);
            $dismissed = (int)Db::val("SELECT id FROM employee_statuses WHERE code = 'dismissed'");
            if ((int)$d['status_id'] === $dismissed && (int)$old['status_id'] !== $dismissed) {
                // уволенному закрывается доступ в систему
                Db::exec('UPDATE users SET is_active = 0 WHERE employee_id = ?', [$id]);
            }
        });
        if ($diff) {
            U::audit('employee.update', 'employee', $id, array_map(fn($x) => $x[0], $diff), array_map(fn($x) => $x[1], $diff));
        }
        return self::card($id);
    }

    /** PUT /employees/{id}/schedule: {days:[{weekday,shift_type,start_time,end_time,break_start,break_end}], exceptions?:[{date,start_time,end_time,is_day_off,reason}]} */
    public static function saveSchedule(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $id = self::mustExist((int)$p['id']);
        $days = self::scheduleInput($r);
        $exc = isset($r->body['exceptions']) && is_array($r->body['exceptions']) ? self::exceptionsInput($r->body['exceptions']) : null;
        Db::tx(function () use ($id, $days, $exc) {
            self::replaceSchedule($id, $days);
            if ($exc !== null) {
                Db::exec('DELETE FROM schedule_exceptions WHERE employee_id = ?', [$id]);
                foreach ($exc as $e) {
                    Db::insert('schedule_exceptions', ['employee_id' => $id] + $e);
                }
            }
        });
        U::audit('employee.schedule', 'employee', $id, null, ['days' => count($days), 'exceptions' => $exc === null ? 'не менялись' : count($exc)]);
        return self::card($id);
    }

    public static function addException(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $id = self::mustExist((int)$p['id']);
        $e = self::exceptionsInput([$r->body])[0];
        Db::exec('DELETE FROM schedule_exceptions WHERE employee_id = ? AND exception_date = ?', [$id, $e['exception_date']]);
        Db::insert('schedule_exceptions', ['employee_id' => $id] + $e);
        U::audit('employee.exception', 'employee', $id, null, $e);
        return self::card($id);
    }

    public static function deleteException(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $id = self::mustExist((int)$p['id']);
        $n = Db::exec('DELETE FROM schedule_exceptions WHERE id = ? AND employee_id = ?', [(int)$p['eid'], $id]);
        if (!$n) {
            throw HttpException::notFound('Исключение из графика не найдено');
        }
        U::audit('employee.exception_delete', 'employee', $id, ['exception_id' => (int)$p['eid']], null);
        return self::card($id);
    }

    /** PUT /employees/{id}/services: {service_ids:[..]} */
    public static function saveServices(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $id = self::mustExist((int)$p['id']);
        $ids = $r->body['service_ids'] ?? $r->body['services'] ?? null;
        if (!is_array($ids)) {
            throw HttpException::bad('Передайте список услуг service_ids');
        }
        $ids = self::idList($ids);
        $before = array_column(Db::all('SELECT service_id FROM employee_services WHERE employee_id = ?', [$id]), 'service_id');
        Db::tx(fn() => self::replaceLinks('employee_services', 'service_id', 'services', $id, $ids));
        U::audit('employee.services', 'employee', $id, ['service_ids' => array_map('intval', $before)], ['service_ids' => $ids]);
        return self::card($id);
    }

    /** PUT /employees/{id}/houses: {house_ids:[..]} */
    public static function saveHouses(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $id = self::mustExist((int)$p['id']);
        $ids = $r->body['house_ids'] ?? $r->body['houses'] ?? null;
        if (!is_array($ids)) {
            throw HttpException::bad('Передайте список домов house_ids');
        }
        $ids = self::idList($ids);
        $before = array_column(Db::all('SELECT house_id FROM employee_houses WHERE employee_id = ?', [$id]), 'house_id');
        Db::tx(fn() => self::replaceLinks('employee_houses', 'house_id', 'houses', $id, $ids));
        U::audit('employee.houses', 'employee', $id, ['house_ids' => array_map('intval', $before)], ['house_ids' => $ids]);
        return self::card($id);
    }

    // ------------------------------------------------------------------ личный кабинет сотрудника

    public static function meSchedule(Request $r): array
    {
        $eid = self::myEmployeeId();
        $emp = Db::one('SELECT e.id, e.last_name, e.first_name, e.middle_name, pos.name AS position_name, st.name AS status_name, st.code AS status_code, sch.name AS schedule_name
            FROM employees e JOIN positions pos ON pos.id = e.position_id JOIN employee_statuses st ON st.id = e.status_id JOIN schedule_types sch ON sch.id = e.schedule_type_id WHERE e.id = ?', [$eid]);
        $days = max(1, min(60, $r->qint('days', 14)));
        $today = date('Y-m-d');
        $upcoming = [];
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("$today +$i day"));
            $upcoming[] = self::effectiveDay($eid, $date);
        }
        return [
            'employee' => ['id' => $eid, 'full_name' => U::fullName($emp), 'short_name' => U::shortName($emp), 'position' => $emp['position_name'], 'status' => ['code' => $emp['status_code'], 'name' => $emp['status_name']], 'schedule_type' => $emp['schedule_name']],
            'schedule' => self::scheduleRows($eid),
            'exceptions' => self::exceptionRows($eid, $today),
            'upcoming' => $upcoming,
            'on_shift_now' => self::onShift($eid, now()),
        ];
    }

    public static function meStats(Request $r): array
    {
        $eid = self::myEmployeeId();
        $now = now();
        $from = $r->q('from') ? U::date($r->q('from'), 'Начало периода') . ' 00:00:00' : date('Y-m-01') . ' 00:00:00';
        $to = $r->q('to') ? U::date($r->q('to'), 'Конец периода') . ' 23:59:59' : $now;
        $loads = U::loads([$eid]);
        $open = Db::all("SELECT st.code, COUNT(*) AS n FROM requests r JOIN request_statuses st ON st.id = r.status_id
            WHERE r.assignee_id = ? AND st.code IN ('assigned','in_progress','done') GROUP BY st.code", [$eid]);
        $byStatus = ['assigned' => 0, 'in_progress' => 0, 'done' => 0];
        foreach ($open as $o) {
            $byStatus[$o['code']] = (int)$o['n'];
        }
        $period = Db::one("SELECT COUNT(*) AS total,
                SUM(r.completed_at IS NOT NULL) AS completed,
                SUM(r.completed_at IS NOT NULL AND r.completed_at <= r.due_at) AS in_time,
                SUM(r.completed_at IS NOT NULL AND r.completed_at > r.due_at) AS late,
                AVG(CASE WHEN r.completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, r.received_at, r.completed_at) END) AS avg_minutes
            FROM requests r JOIN request_statuses st ON st.id = r.status_id
            WHERE r.assignee_id = ? AND st.code NOT IN ('rejected','cancelled') AND r.received_at BETWEEN ? AND ?", [$eid, $from, $to]);
        $overdueNow = (int)Db::val("SELECT COUNT(*) FROM requests r JOIN request_statuses st ON st.id = r.status_id
            WHERE r.assignee_id = ? AND st.code IN ('assigned','in_progress') AND r.due_at < ?", [$eid, $now]);
        $completed = (int)$period['completed'];
        $inTime = (int)$period['in_time'];
        $monthDone = (int)Db::val("SELECT COUNT(*) FROM requests WHERE assignee_id = ? AND completed_at >= ?", [$eid, date('Y-m-01') . ' 00:00:00']);
        return [
            'period' => ['from' => $from, 'to' => $to],
            'load' => $loads[$eid]['load'] ?? 0,
            'max_load' => U::maxLoad(),
            'open_requests' => ($byStatus['assigned'] + $byStatus['in_progress']),
            'by_status' => $byStatus,
            'overdue_now' => $overdueNow,
            'total' => (int)$period['total'],
            'completed' => $completed,
            'completed_this_month' => $monthDone,
            'in_time' => $inTime,
            'late' => (int)$period['late'],
            'in_time_percent' => $completed ? round($inTime * 100 / $completed, 1) : null,
            'avg_resolve_minutes' => $period['avg_minutes'] === null ? null : (int)round((float)$period['avg_minutes']),
            'avg_resolve_hours' => $period['avg_minutes'] === null ? null : round((float)$period['avg_minutes'] / 60, 1),
        ];
    }

    // ------------------------------------------------------------------ график: расчёты

    /** Находится ли сотрудник в рабочей смене на момент $dt (учитывает исключения). */
    public static function onShift(int $employeeId, string $dt): bool
    {
        $date = substr($dt, 0, 10);
        $day = self::effectiveDay($employeeId, $date);
        if (!$day['working']) {
            return false;
        }
        $t = substr($dt, 11, 5);
        $start = $day['start_time'];
        $end = $day['end_time'];
        if ($start === null || $end === null) {
            return false;
        }
        if ($end > $start) {
            return $t >= $start && ($t < $end || $end === '23:59');
        }
        // ночная смена: конец на следующие сутки
        return $t >= $start || $t < $end;
    }

    /** График на конкретную дату с учётом исключений. */
    public static function effectiveDay(int $employeeId, string $date): array
    {
        $wd = (int)date('N', strtotime($date));
        $exc = Db::one('SELECT id, start_time, end_time, is_day_off, reason FROM schedule_exceptions WHERE employee_id = ? AND exception_date = ?', [$employeeId, $date]);
        $base = Db::one('SELECT shift_type, start_time, end_time, break_start, break_end FROM work_schedules WHERE employee_id = ? AND weekday = ?', [$employeeId, $wd]);
        $out = [
            'date' => $date, 'weekday' => $wd, 'weekday_name' => self::WEEKDAYS[$wd], 'working' => false,
            'start_time' => null, 'end_time' => null, 'break_start' => null, 'break_end' => null, 'shift_type' => null,
            'source' => 'schedule', 'reason' => null,
        ];
        if ($exc) {
            $out['source'] = 'exception';
            $out['reason'] = $exc['reason'];
            if ((int)$exc['is_day_off']) {
                return $out;
            }
            $out['working'] = true;
            $out['start_time'] = self::hm($exc['start_time'] ?? ($base['start_time'] ?? null));
            $out['end_time'] = self::hm($exc['end_time'] ?? ($base['end_time'] ?? null));
            $out['shift_type'] = $base['shift_type'] ?? 'day';
            $out['working'] = $out['start_time'] !== null && $out['end_time'] !== null;
            return $out;
        }
        if ($base) {
            $out['working'] = true;
            $out['shift_type'] = $base['shift_type'];
            $out['start_time'] = self::hm($base['start_time']);
            $out['end_time'] = self::hm($base['end_time']);
            $out['break_start'] = self::hm($base['break_start']);
            $out['break_end'] = self::hm($base['break_end']);
        }
        return $out;
    }

    // ------------------------------------------------------------------ внутреннее

    private static function myEmployeeId(): int
    {
        $me = U::me();
        if (!$me['employee_id']) {
            throw HttpException::notFound('Учётная запись не связана с карточкой сотрудника');
        }
        return $me['employee_id'];
    }

    private static function mustExist(int $id): int
    {
        if (!Db::val('SELECT id FROM employees WHERE id = ?', [$id])) {
            throw HttpException::notFound('Сотрудник не найден');
        }
        return $id;
    }

    private static function hm($t): ?string
    {
        return $t === null ? null : substr((string)$t, 0, 5);
    }

    private static function counts(string $table): array
    {
        return array_column(array_map(fn($r) => ['id' => (int)$r['employee_id'], 'n' => (int)$r['n']],
            Db::all("SELECT employee_id, COUNT(*) AS n FROM `$table` GROUP BY employee_id")), 'n', 'id');
    }

    private static function card(int $id): array
    {
        $row = Db::one(self::SELECT . ' WHERE e.id = ?', [$id]);
        if (!$row) {
            throw HttpException::notFound('Сотрудник не найден');
        }
        $it = self::shape($row);
        $loads = U::loads([$id]);
        $it['schedule'] = self::scheduleRows($id);
        $it['exceptions'] = self::exceptionRows($id, date('Y-m-d', strtotime('-30 days')));
        $it['services'] = array_map(fn($s) => ['id' => (int)$s['id'], 'code' => $s['code'], 'name' => $s['name'], 'category_id' => (int)$s['category_id']],
            Db::all('SELECT s.id, s.code, s.name, s.category_id FROM employee_services es JOIN services s ON s.id = es.service_id WHERE es.employee_id = ? ORDER BY s.code', [$id]));
        $it['houses'] = array_map(fn($h) => ['id' => (int)$h['id'], 'address' => $h['address'], 'section_id' => (int)$h['section_id']],
            Db::all('SELECT h.id, h.address, h.section_id FROM employee_houses eh JOIN houses h ON h.id = eh.house_id WHERE eh.employee_id = ? ORDER BY h.address', [$id]));
        $it['sections'] = array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']],
            Db::all('SELECT id, name FROM sections WHERE master_id = ?', [$id]));
        $byCat = Db::all("SELECT c.code, c.name, COUNT(*) AS n, SUM(c.weight) AS l
            FROM requests r JOIN request_categories c ON c.id = r.category_id JOIN request_statuses s ON s.id = r.status_id
            WHERE r.assignee_id = ? AND s.code IN ('assigned','in_progress') GROUP BY c.id, c.code, c.name", [$id]);
        $it['load'] = [
            'current' => $loads[$id]['load'] ?? 0,
            'max' => U::maxLoad(),
            'open_requests' => $loads[$id]['open'] ?? 0,
            'by_category' => array_map(fn($c) => ['code' => $c['code'], 'name' => $c['name'], 'count' => (int)$c['n'], 'load' => (int)$c['l']], $byCat),
        ];
        $it['on_shift_now'] = self::onShift($id, now());
        return $it;
    }

    private static function shape(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'last_name' => $r['last_name'], 'first_name' => $r['first_name'], 'middle_name' => $r['middle_name'],
            'full_name' => U::fullName($r), 'short_name' => U::shortName($r),
            'position' => ['id' => (int)$r['position_id'], 'name' => $r['position_name'], 'is_executor' => (int)$r['is_executor']],
            'status' => ['id' => (int)$r['status_id'], 'code' => $r['status_code'], 'name' => $r['status_name']],
            'schedule_type' => ['id' => (int)$r['schedule_type_id'], 'code' => $r['schedule_code'], 'name' => $r['schedule_name']],
            'contractor' => $r['contractor_id'] ? ['id' => (int)$r['contractor_id'], 'name' => $r['contractor_name']] : null,
            'phone' => $r['phone'], 'email' => $r['email'],
            'hire_date' => $r['hire_date'], 'dismissal_date' => $r['dismissal_date'], 'pd_consent_at' => $r['pd_consent_at'],
            'created_at' => $r['created_at'],
            'user' => $r['user_id'] ? ['id' => (int)$r['user_id'], 'login' => $r['user_login'], 'role' => $r['user_role'], 'is_active' => (int)$r['user_active']] : null,
        ];
    }

    private static function scheduleRows(int $id): array
    {
        $rows = Db::all('SELECT weekday, shift_type, start_time, end_time, break_start, break_end FROM work_schedules WHERE employee_id = ? ORDER BY weekday', [$id]);
        return array_map(fn($w) => [
            'weekday' => (int)$w['weekday'], 'weekday_name' => self::WEEKDAYS[(int)$w['weekday']], 'shift_type' => $w['shift_type'],
            'start_time' => self::hm($w['start_time']), 'end_time' => self::hm($w['end_time']),
            'break_start' => self::hm($w['break_start']), 'break_end' => self::hm($w['break_end']),
        ], $rows);
    }

    private static function exceptionRows(int $id, string $fromDate): array
    {
        $rows = Db::all('SELECT id, exception_date, start_time, end_time, is_day_off, reason FROM schedule_exceptions WHERE employee_id = ? AND exception_date >= ? ORDER BY exception_date', [$id, $fromDate]);
        return array_map(fn($e) => [
            'id' => (int)$e['id'], 'date' => $e['exception_date'], 'start_time' => self::hm($e['start_time']), 'end_time' => self::hm($e['end_time']),
            'is_day_off' => (int)$e['is_day_off'], 'reason' => $e['reason'],
        ], $rows);
    }

    private static function validate(Request $r, ?array $old): array
    {
        $in = static function (string $k, $default = null) use ($r, $old) {
            return array_key_exists($k, $r->body) ? $r->body[$k] : ($old[$k] ?? $default);
        };
        $errors = [];
        $last = trim((string)$in('last_name', ''));
        $first = trim((string)$in('first_name', ''));
        $mid = trim((string)$in('middle_name', ''));
        if ($last === '' || mb_strlen($last) > 50) {
            $errors['last_name'] = 'Фамилия: обязательное поле, не более 50 символов';
        }
        if ($first === '' || mb_strlen($first) > 50) {
            $errors['first_name'] = 'Имя: обязательное поле, не более 50 символов';
        }
        if (mb_strlen($mid) > 50) {
            $errors['middle_name'] = 'Отчество: не более 50 символов';
        }
        $pos = (int)$in('position_id', 0);
        if (!$pos || !Db::val('SELECT id FROM positions WHERE id = ?', [$pos])) {
            $errors['position_id'] = 'Выберите должность';
        }
        $status = self::refId('employee_statuses', $in('status_id'), $r->body['status'] ?? $r->body['status_code'] ?? null, $old ? (int)$old['status_id'] : null)
            ?? (int)Db::val("SELECT id FROM employee_statuses WHERE code = 'active'");
        if (!Db::val('SELECT id FROM employee_statuses WHERE id = ?', [$status])) {
            $errors['status_id'] = 'Недопустимый статус сотрудника';
        }
        $sched = self::refId('schedule_types', $in('schedule_type_id'), $r->body['schedule_type'] ?? $r->body['schedule_code'] ?? null, $old ? (int)$old['schedule_type_id'] : null);
        if (!$sched || !Db::val('SELECT id FROM schedule_types WHERE id = ?', [$sched])) {
            $errors['schedule_type_id'] = 'Выберите тип графика';
        }
        $contractor = $in('contractor_id');
        $contractor = ($contractor === null || $contractor === '' || $contractor === 0) ? null : (int)$contractor;
        if ($contractor !== null && !Db::val('SELECT id FROM contractors WHERE id = ?', [$contractor])) {
            $errors['contractor_id'] = 'Подрядная организация не найдена';
        }
        $phone = trim((string)$in('phone', ''));
        if (!preg_match('/^\+?[0-9\s\-\(\)]{10,20}$/', $phone)) {
            $errors['phone'] = 'Телефон: некорректный номер';
        }
        $email = trim((string)$in('email', ''));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255)) {
            $errors['email'] = 'Некорректный e-mail';
        }
        $hire = $in('hire_date') ?: date('Y-m-d');
        $dism = $in('dismissal_date') ?: null;
        try {
            $hire = U::date($hire, 'Дата приёма');
            $dism = U::date($dism, 'Дата увольнения');
        } catch (HttpException $e) {
            $errors['hire_date'] = $e->getMessage();
        }
        if (!$errors && (int)$status === (int)Db::val("SELECT id FROM employee_statuses WHERE code = 'dismissed'") && !$dism) {
            $dism = date('Y-m-d');
        }
        $pd = $old['pd_consent_at'] ?? null;
        if (array_key_exists('pd_consent', $r->body)) {
            $pd = U::flag($r->body['pd_consent']) ? ($pd ?: now()) : null;
        } elseif (!$old) {
            $pd = now();
        }
        if ($errors) {
            throw HttpException::bad(reset($errors), $errors);
        }
        return [
            'last_name' => $last, 'first_name' => $first, 'middle_name' => $mid === '' ? null : $mid,
            'position_id' => $pos, 'status_id' => $status, 'schedule_type_id' => $sched, 'contractor_id' => $contractor,
            'phone' => $phone, 'email' => $email === '' ? null : $email, 'hire_date' => $hire, 'dismissal_date' => $dism,
            'pd_consent_at' => $pd,
        ];
    }

    /** Определяет id справочника по id или коду. */
    private static function refId(string $table, $id, $code, ?int $fallback): ?int
    {
        if (is_string($code) && $code !== '') {
            $v = Db::val("SELECT id FROM `$table` WHERE code = ?", [$code]);
            if ($v === null) {
                throw HttpException::bad('Недопустимое значение справочника: ' . $code);
            }
            return (int)$v;
        }
        if ($id !== null && $id !== '' && (int)$id > 0) {
            return (int)$id;
        }
        return $fallback;
    }

    /** Необязательная учётная запись при создании сотрудника: login, password, role. */
    private static function validateAccount(Request $r): ?array
    {
        $login = trim((string)($r->body['login'] ?? ''));
        if ($login === '') {
            return null;
        }
        if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $login)) {
            throw HttpException::bad('Логин: 3-50 символов (латиница, цифры, точка, дефис, подчёркивание)');
        }
        if (Db::val('SELECT id FROM users WHERE login = ?', [$login])) {
            throw HttpException::conflict('Пользователь с таким логином уже существует');
        }
        $pw = (string)($r->body['password'] ?? '');
        $min = (int)cfg('security.password_min_len', 8);
        if (mb_strlen($pw) < $min || !preg_match('/[A-Za-zА-Яа-я]/u', $pw) || !preg_match('/\d/', $pw)) {
            throw HttpException::bad("Пароль: не менее $min символов, буквы и цифры");
        }
        $role = (string)($r->body['role'] ?? 'employee');
        $rid = Db::val('SELECT id FROM roles WHERE code = ?', [$role]);
        if (!$rid) {
            throw HttpException::bad('Недопустимая роль');
        }
        return ['login' => $login, 'password' => $pw, 'role_id' => (int)$rid];
    }

    private static function scheduleInput(Request $r): array
    {
        $src = $r->body['days'] ?? $r->body['schedule'] ?? null;
        if (!is_array($src)) {
            throw HttpException::bad('Передайте график: массив days с полями weekday, start_time, end_time');
        }
        $out = [];
        $seen = [];
        foreach ($src as $d) {
            if (!is_array($d)) {
                continue;
            }
            $wd = (int)($d['weekday'] ?? 0);
            if ($wd < 1 || $wd > 7) {
                throw HttpException::bad('День недели: число от 1 (понедельник) до 7 (воскресенье)');
            }
            if (isset($seen[$wd])) {
                throw HttpException::bad('День недели ' . self::WEEKDAYS[$wd] . ' указан дважды');
            }
            $seen[$wd] = true;
            $shift = (string)($d['shift_type'] ?? 'day');
            if (!in_array($shift, ['day', 'night', 'full'], true)) {
                throw HttpException::bad('Тип смены: day, night или full');
            }
            $s = U::time($d['start_time'] ?? null);
            $e = U::time($d['end_time'] ?? null);
            if ($s === null || $e === null) {
                throw HttpException::bad('Для рабочего дня укажите начало и окончание смены');
            }
            if ($shift !== 'night' && $e <= $s && !($s === '00:00:00' && $e === '00:00:00')) {
                throw HttpException::bad('Окончание смены должно быть позже начала (для ночной смены выберите тип night)');
            }
            $bs = U::time($d['break_start'] ?? null);
            $be = U::time($d['break_end'] ?? null);
            if (($bs === null) !== ($be === null) || ($bs !== null && $be <= $bs)) {
                throw HttpException::bad('Перерыв: укажите начало и окончание (окончание позже начала)');
            }
            $out[] = ['weekday' => $wd, 'shift_type' => $shift, 'start_time' => $s, 'end_time' => $e, 'break_start' => $bs, 'break_end' => $be];
        }
        return $out;
    }

    private static function exceptionsInput(array $list): array
    {
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e)) {
                continue;
            }
            $date = U::date($e['date'] ?? $e['exception_date'] ?? null, 'Дата исключения');
            if ($date === null) {
                throw HttpException::bad('Исключение из графика: укажите дату');
            }
            $off = U::flag($e['is_day_off'] ?? 0);
            $s = U::time($e['start_time'] ?? null);
            $en = U::time($e['end_time'] ?? null);
            if (!$off && ($s === null || $en === null)) {
                throw HttpException::bad('Исключение из графика: укажите часы работы или отметьте выходной');
            }
            $reason = trim((string)($e['reason'] ?? ''));
            $out[$date] = [
                'exception_date' => $date, 'start_time' => $off ? null : $s, 'end_time' => $off ? null : $en,
                'is_day_off' => $off, 'reason' => $reason === '' ? null : mb_substr($reason, 0, 255),
            ];
        }
        return array_values($out);
    }

    private static function replaceSchedule(int $id, array $days): void
    {
        Db::exec('DELETE FROM work_schedules WHERE employee_id = ?', [$id]);
        foreach ($days as $d) {
            Db::insert('work_schedules', ['employee_id' => $id] + $d);
        }
    }

    /** График по умолчанию для нового сотрудника по типу графика. */
    private static function defaultSchedule(int $id, int $typeId): void
    {
        $code = (string)Db::val('SELECT code FROM schedule_types WHERE id = ?', [$typeId]);
        $days = [];
        if ($code === 'five_day') {
            for ($d = 1; $d <= 5; $d++) {
                $days[] = ['weekday' => $d, 'shift_type' => 'day', 'start_time' => '09:00:00', 'end_time' => '18:00:00', 'break_start' => '13:00:00', 'break_end' => '14:00:00'];
            }
        } elseif ($code === 'round_clock') {
            for ($d = 1; $d <= 7; $d++) {
                $days[] = ['weekday' => $d, 'shift_type' => 'full', 'start_time' => '00:00:00', 'end_time' => '23:59:00', 'break_start' => null, 'break_end' => null];
            }
        }
        self::replaceSchedule($id, $days);
    }

    private static function idList(array $ids): array
    {
        $out = [];
        foreach ($ids as $v) {
            if (is_array($v)) {
                $v = $v['id'] ?? 0;
            }
            if (!is_numeric($v) || (int)$v <= 0) {
                throw HttpException::bad('Список содержит некорректный идентификатор');
            }
            $out[(int)$v] = (int)$v;
        }
        return array_values($out);
    }

    private static function replaceLinks(string $table, string $col, string $refTable, int $employeeId, array $ids): void
    {
        $ids = self::idList($ids);
        if ($ids) {
            $found = (int)Db::val("SELECT COUNT(*) FROM `$refTable` WHERE id IN (" . Db::in($ids) . ')', $ids);
            if ($found !== count($ids)) {
                throw HttpException::bad('В списке есть несуществующие записи справочника');
            }
        }
        Db::exec("DELETE FROM `$table` WHERE employee_id = ?", [$employeeId]);
        foreach ($ids as $v) {
            Db::insert($table, ['employee_id' => $employeeId, $col => $v]);
        }
    }
}
