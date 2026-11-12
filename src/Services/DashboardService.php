<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Сводка руководителя: счётчики, просрочки и заявки под угрозой, нагрузка исполнителей, доля заявок в срок. */
final class DashboardService
{
    public const PERIODS = ['today', 'week', 'month', 'quarter'];
    private const OPEN = "'new','accepted','assigned','in_progress'";
    private const CATEGORIES = ['emergency', 'urgent', 'planned'];

    /** @return array{0:string,1:string} границы периода [from, to) в формате Y-m-d H:i:s */
    public static function range(string $period): array
    {
        $today = date('Y-m-d');
        switch ($period) {
            case 'week':
                $from = date('Y-m-d', strtotime('monday this week'));
                $to = date('Y-m-d', strtotime($from . ' +7 days'));
                break;
            case 'quarter':
                $m = (int)date('n');
                $qm = $m - (($m - 1) % 3);
                $from = sprintf('%s-%02d-01', date('Y'), $qm);
                $to = date('Y-m-d', strtotime($from . ' +3 months'));
                break;
            case 'today':
                $from = $today;
                $to = date('Y-m-d', strtotime($today . ' +1 day'));
                break;
            default:
                $from = date('Y-m-01');
                $to = date('Y-m-d', strtotime($from . ' +1 month'));
        }
        return [$from . ' 00:00:00', $to . ' 00:00:00'];
    }

    public static function build(string $period, ?int $house = null, int $limit = 20): array
    {
        if (!in_array($period, self::PERIODS, true)) {
            $period = 'month';
        }
        $now = now();
        [$from, $to] = self::range($period);
        $todayFrom = date('Y-m-d') . ' 00:00:00';
        $todayTo = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';
        $hw = $house ? ' AND r.house_id = ?' : '';
        $hp = $house ? [$house] : [];

        $newToday = (int)Db::val("SELECT COUNT(*) FROM requests r WHERE r.received_at >= ? AND r.received_at < ?$hw", array_merge([$todayFrom, $todayTo], $hp));
        $inWork = (int)Db::val("SELECT COUNT(*) FROM requests r JOIN request_statuses s ON s.id = r.status_id WHERE s.code IN ('assigned','in_progress')$hw", $hp);
        $overdue = (int)Db::val("SELECT COUNT(*) FROM requests r JOIN request_statuses s ON s.id = r.status_id WHERE s.code IN (" . self::OPEN . ") AND r.due_at < ?$hw", array_merge([$now], $hp));
        $unassigned = (int)Db::val("SELECT COUNT(*) FROM requests r JOIN request_statuses s ON s.id = r.status_id WHERE s.code IN ('new','accepted') AND r.assignee_id IS NULL$hw", $hp);
        $arriveViol = (int)Db::val("SELECT COUNT(*) FROM requests r JOIN request_statuses s ON s.id = r.status_id
            WHERE s.code IN ('new','accepted','assigned') AND r.arrived_at IS NULL AND r.arrive_due < ?$hw", array_merge([$now], $hp));

        // за период
        $per = Db::one("SELECT COUNT(*) AS received,
                SUM(s.code = 'closed') AS closed,
                SUM(s.code IN ('done','closed')) AS completed,
                SUM(s.code IN ('rejected','cancelled')) AS dropped
            FROM requests r JOIN request_statuses s ON s.id = r.status_id
            WHERE r.received_at >= ? AND r.received_at < ?$hw", array_merge([$from, $to], $hp));

        return [
            'period' => $period,
            'period_from' => $from,
            'period_to' => $to,
            'house' => $house,
            'new_today' => $newToday,
            'in_work' => $inWork,
            'overdue' => $overdue,
            'unassigned' => $unassigned,
            'arrive_overdue' => $arriveViol,
            'period_stats' => [
                'received' => (int)$per['received'], 'completed' => (int)$per['completed'],
                'closed' => (int)$per['closed'], 'dropped' => (int)$per['dropped'],
            ],
            'overdue_list' => self::riskList($now, $house, $limit),
            'workload' => self::workload(),
            'sla' => self::slaPercents($from, $to, $house, $now),
            'sla_detail' => self::slaDetail($from, $to, $house, $now),
            'by_status' => self::byStatus($house),
            'by_category' => self::byCategory($from, $to, $house),
            'daily' => self::daily($house),
        ];
    }

    /** Базовая строка заявки для коротких списков (как элемент GET /requests). */
    public static function shortRow(array $x, string $now): array
    {
        $open = in_array($x['st_code'], ['new', 'accepted', 'assigned', 'in_progress'], true);
        $overdueMin = null;
        if ($open && $x['due_at'] < $now) {
            $overdueMin = Part2Util::minutesBetween($x['due_at'], $now);
        } elseif (!$open && $x['completed_at'] !== null && $x['completed_at'] > $x['due_at']) {
            $overdueMin = Part2Util::minutesBetween($x['due_at'], $x['completed_at']);
        }
        return [
            'id' => (int)$x['id'],
            'number' => $x['request_number'],
            'received_at' => $x['received_at'],
            'category' => ['code' => $x['cat_code'], 'name' => $x['cat_name']],
            'house' => ['id' => (int)$x['house_id'], 'address' => $x['address']],
            'premise' => $x['premise_id'] ? ['id' => (int)$x['premise_id'], 'number' => $x['premise_number']] : null,
            'service' => ['id' => (int)$x['service_id'], 'code' => $x['service_code'], 'name' => $x['service_name'], 'is_paid' => (int)$x['is_paid']],
            'client' => ['id' => (int)$x['client_id'], 'full_name' => Part2Util::fullName($x, 'cl_'), 'phone' => $x['cl_phone']],
            'assignee' => $x['emp_id'] ? [
                'id' => (int)$x['emp_id'], 'full_name' => Part2Util::fullName($x, 'e_'), 'short_name' => Part2Util::shortName($x, 'e_'), 'position' => $x['pos_name'],
            ] : null,
            'status' => ['code' => $x['st_code'], 'name' => $x['st_name']],
            'arrive_due' => $x['arrive_due'],
            'due_at' => $x['due_at'],
            'is_overdue' => $overdueMin !== null ? 1 : 0,
            'manual_assign_required' => (int)$x['manual_assign_required'],
            'overdue_minutes' => $overdueMin,
        ];
    }

    /** Просроченные и находящиеся под угрозой заявки, по возрастанию остатка времени. */
    private static function riskList(string $now, ?int $house, int $limit): array
    {
        $soon = date('Y-m-d H:i:s', strtotime($now) + 30 * 60);
        $hw = $house ? ' AND r.house_id = ?' : '';
        $sql = "SELECT r.id, r.request_number, r.received_at, r.arrive_due, r.due_at, r.warn_at, r.arrived_at, r.completed_at, r.manual_assign_required,
                c.code AS cat_code, c.name AS cat_name, h.id AS house_id, h.address, p.id AS premise_id, p.number AS premise_number,
                s.id AS service_id, s.code AS service_code, s.name AS service_name, s.is_paid,
                cl.id AS client_id, cl.last_name AS cl_last_name, cl.first_name AS cl_first_name, cl.middle_name AS cl_middle_name, cl.phone AS cl_phone,
                e.id AS emp_id, e.last_name AS e_last_name, e.first_name AS e_first_name, e.middle_name AS e_middle_name, pos.name AS pos_name,
                st.code AS st_code, st.name AS st_name
            FROM requests r
            JOIN request_categories c ON c.id = r.category_id
            JOIN houses h ON h.id = r.house_id
            LEFT JOIN premises p ON p.id = r.premise_id
            JOIN services s ON s.id = r.service_id
            JOIN clients cl ON cl.id = r.client_id
            LEFT JOIN employees e ON e.id = r.assignee_id
            LEFT JOIN positions pos ON pos.id = e.position_id
            JOIN request_statuses st ON st.id = r.status_id
            WHERE st.code IN (" . self::OPEN . ")
              AND (r.due_at < ?
                   OR (r.arrived_at IS NULL AND st.code IN ('new','accepted','assigned') AND r.arrive_due <= ?)
                   OR r.warn_at <= ?)$hw";
        $rows = Db::all($sql, array_merge([$now, $soon, $now], $house ? [$house] : []));
        $items = [];
        foreach ($rows as $x) {
            $it = self::shortRow($x, $now);
            if ($x['due_at'] < $now) {
                $it['kind'] = 'overdue';
                $it['deadline_at'] = $x['due_at'];
                $mins = -Part2Util::minutesBetween($x['due_at'], $now);
                $it['label'] = 'просрочено ' . Part2Util::minutesText($mins);
            } elseif ($x['arrived_at'] === null && in_array($x['st_code'], ['new', 'accepted', 'assigned'], true) && $x['arrive_due'] <= $soon) {
                $mins = Part2Util::minutesBetween($now, $x['arrive_due']);
                $it['kind'] = $mins < 0 ? 'arrive_overdue' : 'arrive';
                $it['deadline_at'] = $x['arrive_due'];
                $it['label'] = $mins < 0 ? 'выезд просрочен на ' . Part2Util::minutesText($mins) : 'выезд через ' . Part2Util::minutesText($mins);
            } else {
                $mins = Part2Util::minutesBetween($now, $x['due_at']);
                $it['kind'] = 'warn';
                $it['deadline_at'] = $x['due_at'];
                $it['label'] = 'осталось ' . Part2Util::minutesText($mins);
            }
            $it['minutes_left'] = Part2Util::minutesBetween($now, $it['deadline_at']);
            $items[] = $it;
        }
        // сначала просроченные (по величине просрочки), затем по остатку времени
        usort($items, function ($a, $b) {
            $ra = $a['kind'] === 'overdue' ? 0 : 1;
            $rb = $b['kind'] === 'overdue' ? 0 : 1;
            return $ra <=> $rb ?: $a['minutes_left'] <=> $b['minutes_left'];
        });
        return array_slice($items, 0, max(1, $limit));
    }

    /** Нагрузка исполнителей (активных): L = сумма весов открытых заявок. */
    private static function workload(): array
    {
        $rows = Db::all("SELECT e.id, e.last_name, e.first_name, e.middle_name, pos.name AS position_name
            FROM employees e JOIN positions pos ON pos.id = e.position_id JOIN employee_statuses st ON st.id = e.status_id
            WHERE pos.is_executor = 1 AND st.code = 'active' ORDER BY e.last_name, e.first_name");
        $loads = Part2Util::loads();
        $max = Part2Util::maxLoad();
        $out = [];
        foreach ($rows as $e) {
            $id = (int)$e['id'];
            $out[] = [
                'employee' => Part2Util::shortName($e),
                'employee_id' => $id,
                'full_name' => Part2Util::fullName($e),
                'position' => $e['position_name'],
                'load' => $loads[$id]['load'] ?? 0,
                'open_requests' => $loads[$id]['open'] ?? 0,
                'max_load' => $max,
            ];
        }
        usort($out, fn($a, $b) => $b['load'] <=> $a['load'] ?: strcmp($a['employee'], $b['employee']));
        return $out;
    }

    /** @return array<string,array{total:int,on_time:int,violated:int,percent:?float}> */
    private static function slaDetail(string $from, string $to, ?int $house, string $now): array
    {
        $hw = $house ? ' AND r.house_id = ?' : '';
        $rows = Db::all("SELECT c.code,
                SUM(r.completed_at IS NOT NULL AND r.completed_at <= r.due_at) AS on_time,
                SUM((r.completed_at IS NOT NULL AND r.completed_at > r.due_at) OR (r.completed_at IS NULL AND r.due_at < ?)) AS violated
            FROM requests r JOIN request_categories c ON c.id = r.category_id JOIN request_statuses s ON s.id = r.status_id
            WHERE s.code NOT IN ('rejected','cancelled') AND r.received_at >= ? AND r.received_at < ?$hw
            GROUP BY c.code", array_merge([$now, $from, $to], $house ? [$house] : []));
        $by = array_column($rows, null, 'code');
        $out = [];
        foreach (self::CATEGORIES as $code) {
            $ok = (int)($by[$code]['on_time'] ?? 0);
            $bad = (int)($by[$code]['violated'] ?? 0);
            $tot = $ok + $bad;
            $out[$code] = ['total' => $tot, 'on_time' => $ok, 'violated' => $bad, 'percent' => $tot ? round($ok * 100 / $tot, 1) : null];
        }
        return $out;
    }

    private static function slaPercents(string $from, string $to, ?int $house, string $now): array
    {
        return array_map(fn($d) => $d['percent'], self::slaDetail($from, $to, $house, $now));
    }

    private static function byStatus(?int $house): array
    {
        $rows = Db::all("SELECT s.code, s.name, COUNT(r.id) AS n FROM request_statuses s LEFT JOIN requests r ON r.status_id = s.id" . ($house ? ' AND r.house_id = ?' : '') . ' GROUP BY s.id, s.code, s.name ORDER BY s.sort_order', $house ? [$house] : []);
        return array_map(fn($x) => ['code' => $x['code'], 'name' => $x['name'], 'count' => (int)$x['n']], $rows);
    }

    private static function byCategory(string $from, string $to, ?int $house): array
    {
        $rows = Db::all("SELECT c.code, c.name, COUNT(r.id) AS n FROM request_categories c
            LEFT JOIN requests r ON r.category_id = c.id AND r.received_at >= ? AND r.received_at < ?" . ($house ? ' AND r.house_id = ?' : '') . '
            GROUP BY c.id, c.code, c.name ORDER BY c.id', array_merge([$from, $to], $house ? [$house] : []));
        return array_map(fn($x) => ['code' => $x['code'], 'name' => $x['name'], 'count' => (int)$x['n']], $rows);
    }

    /** Поступление и закрытие по дням за последние 7 суток. */
    private static function daily(?int $house): array
    {
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $a = $d . ' 00:00:00';
            $b = date('Y-m-d', strtotime("$d +1 day")) . ' 00:00:00';
            $hp = $house ? [$house] : [];
            $hw = $house ? ' AND house_id = ?' : '';
            $out[] = [
                'date' => $d,
                'received' => (int)Db::val("SELECT COUNT(*) FROM requests WHERE received_at >= ? AND received_at < ?$hw", array_merge([$a, $b], $hp)),
                'completed' => (int)Db::val("SELECT COUNT(*) FROM requests WHERE completed_at >= ? AND completed_at < ?$hw", array_merge([$a, $b], $hp)),
            ];
        }
        return $out;
    }
}
