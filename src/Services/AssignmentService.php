<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Settings;
use DateTimeImmutable;

/** Подбор и назначение исполнителя (ТЗ 7.6, «Автоназначение»). */
final class AssignmentService
{
    /** Нагрузка L = сумма весов категорий по открытым заявкам (назначена / выполняется). */
    public static function load(int $employeeId): int
    {
        return (int)Db::val(
            "SELECT COALESCE(SUM(c.weight), 0) FROM requests r
             JOIN request_categories c ON c.id = r.category_id
             JOIN request_statuses s ON s.id = r.status_id
             WHERE r.assignee_id = ? AND s.code IN ('assigned', 'in_progress')", [$employeeId]);
    }

    /** Находится ли сотрудник в рабочей смене в момент $at (с учётом исключений из графика). */
    public static function isOnSchedule(int $employeeId, string $scheduleType, DateTimeImmutable $at): bool
    {
        $date = $at->format('Y-m-d');
        $t = $at->format('H:i:s');
        $ex = Db::one('SELECT start_time, end_time, is_day_off FROM schedule_exceptions WHERE employee_id = ? AND exception_date = ?', [$employeeId, $date]);
        if ($ex) {
            if ((int)$ex['is_day_off']) {
                return false;
            }
            if ($ex['start_time'] !== null && $ex['end_time'] !== null) {
                return self::inRange($t, $ex['start_time'], $ex['end_time']);
            }
        }
        if ($scheduleType === 'round_clock') {
            return true;
        }
        foreach (Db::all('SELECT shift_type, start_time, end_time FROM work_schedules WHERE employee_id = ? AND weekday = ?', [$employeeId, (int)$at->format('N')]) as $w) {
            if ($w['shift_type'] === 'full' || self::inRange($t, $w['start_time'], $w['end_time'], true)) {
                return true;
            }
        }
        // ночная смена предыдущего дня, закончившаяся утром
        $prev = (int)$at->modify('-1 day')->format('N');
        foreach (Db::all("SELECT start_time, end_time FROM work_schedules WHERE employee_id = ? AND weekday = ? AND start_time > end_time", [$employeeId, $prev]) as $w) {
            if ($t < $w['end_time']) {
                return true;
            }
        }
        return false;
    }

    private static function inRange(string $t, string $start, string $end, bool $dayPartOnly = false): bool
    {
        if ($start <= $end) {
            return $t >= $start && $t < $end;
        }
        // смена через полночь: в день начала действует только вечерняя часть
        return $dayPartOnly ? $t >= $start : ($t >= $start || $t < $end);
    }

    /** Применимое правило автоназначения: наименьший priority среди подходящих; иначе предел по умолчанию. */
    public static function rule(int $categoryId, int $serviceId, int $houseId): array
    {
        $svcCat = (int)Db::val('SELECT category_id FROM services WHERE id = ?', [$serviceId]);
        $chain = [];
        for ($c = $svcCat, $i = 0; $c && $i < 10; $i++) {
            $chain[] = $c;
            $c = (int)Db::val('SELECT parent_id FROM service_categories WHERE id = ?', [$c]);
        }
        $section = (int)Db::val('SELECT section_id FROM houses WHERE id = ?', [$houseId]);
        foreach (Db::all('SELECT * FROM assignment_rules WHERE is_active = 1 ORDER BY priority ASC, id ASC') as $r) {
            if ($r['request_category_id'] !== null && (int)$r['request_category_id'] !== $categoryId) {
                continue;
            }
            if ($r['service_category_id'] !== null && !in_array((int)$r['service_category_id'], $chain, true)) {
                continue;
            }
            if ($r['section_id'] !== null && (int)$r['section_id'] !== $section) {
                continue;
            }
            return ['rule_id' => (int)$r['id'], 'max_load' => (int)$r['max_load'], 'allow_off_schedule' => (bool)$r['allow_off_schedule']];
        }
        return ['rule_id' => null, 'max_load' => Settings::int('default_max_load', 9), 'allow_off_schedule' => false];
    }

    /**
     * Все активные исполнители с признаками пригодности и рекомендуемый выбор.
     * @param array $ctx category_id, service_id, house_id
     * @return array список кандидатов; у рекомендуемого recommended = true
     */
    public static function candidates(array $ctx, ?DateTimeImmutable $at = null, ?int $excludeId = null): array
    {
        $at ??= new DateTimeImmutable();
        $cat = Db::one('SELECT id, code, weight FROM request_categories WHERE id = ?', [(int)$ctx['category_id']]);
        if (!$cat) {
            return [];
        }
        $rule = self::rule((int)$cat['id'], (int)$ctx['service_id'], (int)$ctx['house_id']);
        $weight = (int)$cat['weight'];
        $allowOff = $cat['code'] === 'emergency' || $rule['allow_off_schedule'];
        $rows = Db::all(
            "SELECT e.id, e.last_name, e.first_name, e.middle_name, p.name AS position, st.code AS status_code, sc.code AS schedule_type,
                    EXISTS(SELECT 1 FROM employee_services es WHERE es.employee_id = e.id AND es.service_id = ?) AS has_service,
                    EXISTS(SELECT 1 FROM employee_houses eh WHERE eh.employee_id = e.id AND eh.house_id = ?) AS has_house,
                    (SELECT MAX(ra.assigned_at) FROM request_assignments ra WHERE ra.employee_id = e.id) AS last_assigned
             FROM employees e
             JOIN positions p ON p.id = e.position_id AND p.is_executor = 1
             JOIN employee_statuses st ON st.id = e.status_id AND st.code = 'active'
             JOIN schedule_types sc ON sc.id = e.schedule_type_id
             ORDER BY e.last_name, e.first_name",
            [(int)$ctx['service_id'], (int)$ctx['house_id']]);
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $load = self::load($id);
            $on = self::isOnSchedule($id, $r['schedule_type'], $at);
            $matches = (bool)$r['has_service'] && (bool)$r['has_house'];
            $within = $load + $weight <= $rule['max_load'];
            $out[] = [
                'id' => $id, 'employee_id' => $id,
                'full_name' => trim("{$r['last_name']} {$r['first_name']} {$r['middle_name']}"),
                'short_name' => self::short($r),
                'position' => $r['position'],
                'load' => $load, 'max_load' => $rule['max_load'],
                'on_schedule' => $on, 'has_service' => (bool)$r['has_service'], 'has_house' => (bool)$r['has_house'],
                'matches' => $matches, 'within_limit' => $within,
                'last_assigned' => $r['last_assigned'],
                'is_excluded' => $excludeId === $id,
                'recommended' => false, 'off_schedule' => false,
            ];
        }
        $pick = function (bool $onSchedule) use (&$out, $excludeId) {
            $pool = array_filter($out, fn($c) => $c['matches'] && $c['within_limit'] && $c['on_schedule'] === $onSchedule && $c['id'] !== $excludeId);
            usort($pool, fn($a, $b) => [$a['load'], $a['last_assigned'] ?? '', $a['id']] <=> [$b['load'], $b['last_assigned'] ?? '', $b['id']]);
            return $pool ? reset($pool)['id'] : null;
        };
        $best = $pick(true);
        $off = false;
        if ($best === null && $allowOff) {
            $best = $pick(false);
            $off = $best !== null;
        }
        foreach ($out as &$c) {
            if ($c['id'] === $best) {
                $c['recommended'] = true;
                $c['off_schedule'] = $off;
            }
        }
        unset($c);
        usort($out, fn($a, $b) => [!$a['recommended'], !$a['matches'], !$a['on_schedule'], $a['load'], $a['short_name']]
            <=> [!$b['recommended'], !$b['matches'], !$b['on_schedule'], $b['load'], $b['short_name']]);
        return $out;
    }

    /** Рекомендуемый кандидат или null (заявка уйдёт в очередь). */
    public static function pick(array $ctx, ?DateTimeImmutable $at = null, ?int $excludeId = null): ?array
    {
        foreach (self::candidates($ctx, $at, $excludeId) as $c) {
            if ($c['recommended']) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Назначает исполнителя (запись в request_assignments, смена статуса на «Назначен исполнитель», уведомления).
     * Для ручного назначения проверяется только, что сотрудник активен и является исполнителем.
     */
    public static function assign(int $requestId, int $employeeId, ?int $userId, string $mode, ?string $reason = null, ?bool $offSchedule = null): array
    {
        $emp = Db::one("SELECT e.id, e.last_name, e.first_name, e.middle_name, st.code AS status_code, sc.code AS schedule_type, p.is_executor
                        FROM employees e JOIN employee_statuses st ON st.id = e.status_id
                        JOIN schedule_types sc ON sc.id = e.schedule_type_id JOIN positions p ON p.id = e.position_id WHERE e.id = ?", [$employeeId]);
        if (!$emp) {
            throw HttpException::notFound('Сотрудник не найден');
        }
        if ($emp['status_code'] !== 'active') {
            throw HttpException::bad('Сотрудник недоступен для назначения: статус не «Активен»');
        }
        if (!(int)$emp['is_executor']) {
            throw HttpException::bad('Эта должность не выполняет заявки');
        }
        return Db::tx(function () use ($requestId, $employeeId, $userId, $mode, $reason, $offSchedule, $emp) {
            $req = Db::one('SELECT r.*, s.code AS status_code, c.code AS category_code FROM requests r
                            JOIN request_statuses s ON s.id = r.status_id JOIN request_categories c ON c.id = r.category_id
                            WHERE r.id = ? FOR UPDATE', [$requestId]);
            if (!$req) {
                throw HttpException::notFound('Заявка не найдена');
            }
            if (!in_array($req['status_code'], ['new', 'accepted', 'assigned', 'in_progress'], true)) {
                throw HttpException::conflict('Для заявки в этом статусе назначение невозможно');
            }
            $off = $offSchedule ?? !self::isOnSchedule($employeeId, $emp['schedule_type'], new DateTimeImmutable());
            $prev = $req['assignee_id'] !== null ? (int)$req['assignee_id'] : null;
            Db::exec('UPDATE request_assignments SET unassigned_at = ? WHERE request_id = ? AND unassigned_at IS NULL', [now(), $requestId]);
            Db::insert('request_assignments', [
                'request_id' => $requestId, 'employee_id' => $employeeId, 'assigned_by' => $userId, 'mode' => $mode,
                'off_schedule' => $off ? 1 : 0, 'assigned_at' => now(), 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            ]);
            Db::exec('UPDATE requests SET assignee_id = ?, manual_assign_required = 0, updated_at = ? WHERE id = ?', [$employeeId, now(), $requestId]);
            if ($req['status_code'] === 'new') {
                RequestService::transition($requestId, 'accepted', $userId, null);
                $req['status_code'] = 'accepted';
            }
            if ($req['status_code'] === 'accepted') {
                RequestService::transition($requestId, 'assigned', $mode === 'auto' ? null : $userId, $mode === 'auto' ? 'автоназначение' : ($reason ?: 'назначено диспетчером'));
            }
            $short = self::short($emp);
            $num = $req['request_number'];
            if ($prev !== null && $prev !== $employeeId) {
                NotificationService::employee($prev, 'reassigned', "Заявка $num переназначена другому исполнителю", $requestId);
            }
            if ($prev !== $employeeId) {
                NotificationService::employee($employeeId, 'new_request', "Вам назначена заявка $num (срок устранения " . self::fmt($req['due_at']) . ')', $requestId);
                NotificationService::client((int)$req['client_id'], 'request_assigned', "ПосадДом: по заявке $num назначен исполнитель $short.", $requestId);
            }
            if ($off) {
                NotificationService::employee($employeeId, 'off_schedule', "Вызов вне графика: аварийная заявка $num", $requestId);
            }
            Log::audit($mode === 'auto' ? 'request.auto_assign' : 'request.assign', 'request', $requestId,
                $prev !== null ? ['assignee_id' => $prev] : null, ['assignee_id' => $employeeId, 'off_schedule' => $off, 'reason' => $reason], $userId);
            return ['employee_id' => $employeeId, 'short_name' => $short, 'off_schedule' => $off];
        });
    }

    /**
     * Автоназначение: назначает рекомендуемого кандидата либо ставит заявку в очередь нераспределённых.
     * @return array|null назначенный сотрудник или null, если заявка попала в очередь
     */
    public static function autoAssign(int $requestId, ?int $userId, ?DateTimeImmutable $at = null, ?int $excludeId = null): ?array
    {
        $req = Db::one('SELECT category_id, service_id, house_id, request_number, assignee_id FROM requests WHERE id = ?', [$requestId]);
        if (!$req) {
            throw HttpException::notFound('Заявка не найдена');
        }
        $c = self::pick($req, $at, $excludeId);
        if ($c) {
            return self::assign($requestId, $c['id'], $userId, 'auto', null, $c['off_schedule']);
        }
        Db::exec('UPDATE requests SET manual_assign_required = 1, updated_at = ? WHERE id = ?', [now(), $requestId]);
        NotificationService::dispatchers('queued', "Заявка {$req['request_number']} не распределена автоматически: требуется назначение вручную", $requestId);
        return null;
    }

    public static function short(array $e): string
    {
        $s = $e['last_name'] . ' ' . mb_substr((string)$e['first_name'], 0, 1) . '.';
        return !empty($e['middle_name']) ? $s . mb_substr($e['middle_name'], 0, 1) . '.' : $s;
    }

    private static function fmt(string $dt): string
    {
        return date('d.m.Y H:i', strtotime($dt));
    }
}
