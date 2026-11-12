<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;

/** Пять отчётов ТЗ 7.6 п. 12: по заявкам, исполнителям, платным услугам, просроченным, обращениям клиентов. */
final class ReportService
{
    public const TYPES = [
        'requests' => 'Заявки за период',
        'executors' => 'Исполнители',
        'paid' => 'Платные услуги',
        'overdue' => 'Просроченные заявки',
        'clients' => 'Обращения клиентов',
    ];

    /** Какие отчёты доступны ролям (администратор и руководитель - все). */
    public const ROLE_TYPES = [
        'admin' => ['requests', 'executors', 'paid', 'overdue', 'clients'],
        'manager' => ['requests', 'executors', 'paid', 'overdue', 'clients'],
        'dispatcher' => ['requests', 'overdue', 'clients'],
    ];

    private const OPEN = "'new','accepted','assigned','in_progress'";

    private const FROM = "FROM requests r
        JOIN request_categories c ON c.id = r.category_id
        JOIN request_statuses st ON st.id = r.status_id
        JOIN services s ON s.id = r.service_id
        JOIN houses h ON h.id = r.house_id
        JOIN clients cl ON cl.id = r.client_id
        LEFT JOIN premises p ON p.id = r.premise_id
        LEFT JOIN employees e ON e.id = r.assignee_id";

    public static function filters(array $q): array
    {
        $today = date('Y-m-d');
        $to = self::d($q['to'] ?? null, 'Дата «по»') ?? $today;
        $from = self::d($q['from'] ?? null, 'Дата «с»') ?? date('Y-m-d', strtotime($to . ' -29 days'));
        if ($from > $to) {
            throw HttpException::bad('Дата начала периода позже даты окончания');
        }
        $f = ['from' => $from, 'to' => $to, 'category' => null, 'status' => null, 'service' => null, 'employee' => null, 'house' => null];
        foreach (['category', 'status', 'service', 'employee', 'house'] as $k) {
            $v = isset($q[$k]) ? trim((string)$q[$k]) : '';
            $f[$k] = $v === '' ? null : $v;
        }
        return $f;
    }

    public static function run(string $type, array $f): array
    {
        if (!isset(self::TYPES[$type])) {
            throw HttpException::notFound('Неизвестный отчёт');
        }
        $res = match ($type) {
            'requests' => self::requests($f),
            'executors' => self::executors($f),
            'paid' => self::paid($f),
            'overdue' => self::overdue($f),
            'clients' => self::clients($f),
        };
        $res['type'] = $type;
        $res['title'] = self::TYPES[$type];
        $res['filters'] = $f;
        return $res;
    }

    // ------------------------------------------------------------------ отчёты

    private static function requests(array $f): array
    {
        [$w, $p] = self::where($f, 'r.received_at');
        $rows = Db::all("SELECT r.request_number, r.received_at, c.name AS category, c.code AS cat_code, st.name AS status, st.code AS st_code,
                h.address, p.number AS premise, s.code AS service_code, s.name AS service, s.is_paid,
                cl.last_name AS cl_last_name, cl.first_name AS cl_first_name, cl.middle_name AS cl_middle_name,
                e.last_name AS e_last_name, e.first_name AS e_first_name, e.middle_name AS e_middle_name,
                r.arrive_due, r.due_at, r.arrived_at, r.completed_at, r.closed_at
            " . self::FROM . " $w ORDER BY r.received_at DESC, r.id DESC", $p);
        $now = now();
        $out = [];
        $overdue = 0;
        $closed = 0;
        $byCat = [];
        foreach ($rows as $x) {
            $late = self::lateMinutes($x['st_code'], $x['due_at'], $x['completed_at'], $now);
            $overdue += $late !== null ? 1 : 0;
            $closed += $x['st_code'] === 'closed' ? 1 : 0;
            $byCat[$x['category']] = ($byCat[$x['category']] ?? 0) + 1;
            $out[] = [
                'number' => $x['request_number'], 'received_at' => $x['received_at'], 'category' => $x['category'], 'status' => $x['status'],
                'address' => self::addr($x['address'], $x['premise']), 'service' => $x['service_code'] . ' ' . $x['service'],
                'client' => Part2Util::shortName($x, 'cl_'), 'assignee' => $x['e_last_name'] !== null ? Part2Util::shortName($x, 'e_') : 'не назначен',
                'arrive_due' => $x['arrive_due'], 'due_at' => $x['due_at'], 'completed_at' => $x['completed_at'],
                'overdue' => $late === null ? 'нет' : 'да, ' . Part2Util::minutesText($late),
            ];
        }
        $summary = ['total' => count($out), 'closed' => $closed, 'overdue' => $overdue];
        $titles = ['total' => 'Всего заявок', 'closed' => 'Закрыто', 'overdue' => 'С просрочкой'];
        foreach ($byCat as $name => $n) {
            $summary['cat_' . $name] = $n;
            $titles['cat_' . $name] = $name;
        }
        return [
            'columns' => self::cols(['number' => '№ заявки', 'received_at' => 'Поступила', 'category' => 'Категория', 'status' => 'Статус', 'address' => 'Адрес',
                'service' => 'Услуга', 'client' => 'Клиент', 'assignee' => 'Исполнитель', 'arrive_due' => 'Срок выезда', 'due_at' => 'Срок устранения',
                'completed_at' => 'Выполнена', 'overdue' => 'Просрочка']),
            'rows' => $out, 'summary' => $summary, 'summary_titles' => $titles,
        ];
    }

    private static function executors(array $f): array
    {
        [$w, $p] = self::where($f, 'r.received_at');
        $w .= ($w === '' ? 'WHERE' : ' AND') . ' r.assignee_id IS NOT NULL';
        $rows = Db::all("SELECT r.assignee_id, r.received_at, r.due_at, r.completed_at, st.code AS st_code " . self::FROM . " $w", $p);
        $now = now();
        $agg = [];
        foreach ($rows as $x) {
            $id = (int)$x['assignee_id'];
            $a = &$agg[$id];
            $a = $a ?? ['assigned' => 0, 'completed' => 0, 'in_time' => 0, 'overdue' => 0, 'minutes' => 0];
            $a['assigned']++;
            if ($x['completed_at'] !== null) {
                $a['completed']++;
                $a['minutes'] += Part2Util::minutesBetween($x['received_at'], $x['completed_at']);
                if ($x['completed_at'] <= $x['due_at']) {
                    $a['in_time']++;
                }
            }
            if (self::lateMinutes($x['st_code'], $x['due_at'], $x['completed_at'], $now) !== null) {
                $a['overdue']++;
            }
            unset($a);
        }
        $emps = Db::all("SELECT e.id, e.last_name, e.first_name, e.middle_name, pos.name AS position_name
            FROM employees e JOIN positions pos ON pos.id = e.position_id JOIN employee_statuses es ON es.id = e.status_id
            WHERE pos.is_executor = 1 AND (es.code <> 'dismissed' OR e.id IN (" . Db::in(array_keys($agg) ?: [0]) . ')) ORDER BY e.last_name, e.first_name', array_keys($agg) ?: [0]);
        $empFilter = $f['employee'] !== null ? (int)$f['employee'] : null;
        $loads = Part2Util::loads();
        $out = [];
        $tot = ['assigned' => 0, 'completed' => 0, 'overdue' => 0];
        foreach ($emps as $e) {
            $id = (int)$e['id'];
            if ($empFilter !== null && $empFilter !== $id) {
                continue;
            }
            $a = $agg[$id] ?? ['assigned' => 0, 'completed' => 0, 'in_time' => 0, 'overdue' => 0, 'minutes' => 0];
            $out[] = [
                'employee' => Part2Util::fullName($e), 'position' => $e['position_name'],
                'assigned' => $a['assigned'], 'completed' => $a['completed'],
                'avg_hours' => $a['completed'] ? round($a['minutes'] / $a['completed'] / 60, 1) : null,
                'overdue' => $a['overdue'],
                'in_time_percent' => $a['completed'] ? round($a['in_time'] * 100 / $a['completed'], 1) : null,
                'load' => $loads[$id]['load'] ?? 0,
            ];
            $tot['assigned'] += $a['assigned'];
            $tot['completed'] += $a['completed'];
            $tot['overdue'] += $a['overdue'];
        }
        return [
            'columns' => self::cols(['employee' => 'Исполнитель', 'position' => 'Должность', 'assigned' => 'Назначено заявок', 'completed' => 'Выполнено',
                'avg_hours' => 'Среднее время устранения, ч', 'overdue' => 'Просрочки', 'in_time_percent' => 'Выполнено в срок, %', 'load' => 'Текущая нагрузка']),
            'rows' => $out,
            'summary' => ['executors' => count($out)] + $tot,
            'summary_titles' => ['executors' => 'Исполнителей', 'assigned' => 'Назначено заявок', 'completed' => 'Выполнено', 'overdue' => 'Просрочек'],
        ];
    }

    private static function paid(array $f): array
    {
        [$w, $p] = self::where($f, 'ch.created_at');
        $w .= ($w === '' ? 'WHERE' : ' AND') . " ch.status = 'accrued'";
        $rows = Db::all("SELECT r.request_number, ch.created_at AS charged_at, r.closed_at, s.code AS service_code, s.name AS service,
                cl.last_name AS cl_last_name, cl.first_name AS cl_first_name, cl.middle_name AS cl_middle_name,
                h.address, p.number AS premise, ch.amount, ch.basis, st.name AS status
            FROM charges ch
            JOIN requests r ON r.id = ch.request_id
            JOIN request_categories c ON c.id = r.category_id
            JOIN request_statuses st ON st.id = r.status_id
            JOIN services s ON s.id = ch.service_id
            JOIN houses h ON h.id = r.house_id
            JOIN clients cl ON cl.id = r.client_id
            LEFT JOIN premises p ON p.id = r.premise_id
            LEFT JOIN employees e ON e.id = r.assignee_id
            $w ORDER BY ch.created_at DESC, ch.id DESC", $p);
        $sum = 0.0;
        $out = [];
        foreach ($rows as $x) {
            $sum += (float)$x['amount'];
            $out[] = [
                'number' => $x['request_number'], 'charged_at' => $x['charged_at'], 'closed_at' => $x['closed_at'],
                'service' => $x['service_code'] . ' ' . $x['service'], 'client' => Part2Util::shortName($x, 'cl_'),
                'address' => self::addr($x['address'], $x['premise']), 'amount' => round((float)$x['amount'], 2), 'basis' => $x['basis'],
            ];
        }
        return [
            'columns' => self::cols(['number' => '№ заявки', 'charged_at' => 'Дата начисления', 'closed_at' => 'Закрыта', 'service' => 'Услуга', 'client' => 'Клиент',
                'address' => 'Адрес', 'amount' => 'Сумма, руб.', 'basis' => 'Основание']),
            'rows' => $out,
            'summary' => ['count' => count($out), 'total_amount' => round($sum, 2)],
            'summary_titles' => ['count' => 'Начислений', 'total_amount' => 'Сумма начислений, руб.'],
        ];
    }

    private static function overdue(array $f): array
    {
        [$w, $p] = self::where($f, 'r.received_at');
        $now = now();
        $cond = "st.code NOT IN ('rejected','cancelled') AND ((r.completed_at IS NULL AND st.code IN (" . self::OPEN . ") AND r.due_at < ?) OR r.completed_at > r.due_at)";
        $w .= ($w === '' ? 'WHERE ' : ' AND ') . $cond;
        $p[] = $now;
        $rows = Db::all("SELECT r.request_number, r.received_at, c.name AS category, st.name AS status, st.code AS st_code, h.address, p.number AS premise,
                s.code AS service_code, s.name AS service,
                e.last_name AS e_last_name, e.first_name AS e_first_name, e.middle_name AS e_middle_name,
                r.arrive_due, r.arrived_at, r.due_at, r.completed_at
            " . self::FROM . " $w ORDER BY r.due_at", $p);
        $out = [];
        $open = 0;
        $max = 0;
        $sumMin = 0;
        foreach ($rows as $x) {
            $min = Part2Util::minutesBetween($x['due_at'], $x['completed_at'] ?? $now);
            $isOpen = $x['completed_at'] === null;
            $open += $isOpen ? 1 : 0;
            $max = max($max, $min);
            $sumMin += $min;
            $arriveLate = $x['arrived_at'] !== null ? $x['arrived_at'] > $x['arrive_due'] : $x['arrive_due'] < $now;
            $out[] = [
                'number' => $x['request_number'], 'category' => $x['category'], 'address' => self::addr($x['address'], $x['premise']),
                'service' => $x['service_code'] . ' ' . $x['service'], 'assignee' => $x['e_last_name'] !== null ? Part2Util::shortName($x, 'e_') : 'не назначен',
                'status' => $x['status'], 'due_at' => $x['due_at'], 'completed_at' => $x['completed_at'],
                'overdue_minutes' => $min, 'overdue_text' => Part2Util::minutesText($min), 'arrive_violation' => $arriveLate ? 'да' : 'нет',
            ];
        }
        usort($out, fn($a, $b) => $b['overdue_minutes'] <=> $a['overdue_minutes']);
        return [
            'columns' => self::cols(['number' => '№ заявки', 'category' => 'Категория', 'address' => 'Адрес', 'service' => 'Услуга', 'assignee' => 'Исполнитель',
                'status' => 'Статус', 'due_at' => 'Срок устранения', 'completed_at' => 'Выполнена', 'overdue_minutes' => 'Просрочка, мин', 'overdue_text' => 'Просрочка', 'arrive_violation' => 'Нарушен срок выезда']),
            'rows' => $out,
            'summary' => ['total' => count($out), 'still_open' => $open, 'max_overdue_minutes' => $max, 'avg_overdue_minutes' => $out ? (int)round($sumMin / count($out)) : 0],
            'summary_titles' => ['total' => 'Просроченных заявок', 'still_open' => 'Из них ещё не выполнено', 'max_overdue_minutes' => 'Наибольшая просрочка, мин', 'avg_overdue_minutes' => 'Средняя просрочка, мин'],
        ];
    }

    private static function clients(array $f): array
    {
        [$w, $p] = self::where($f, 'r.received_at');
        $rows = Db::all("SELECT cl.id, cl.last_name AS cl_last_name, cl.first_name AS cl_first_name, cl.middle_name AS cl_middle_name, cl.phone,
                COUNT(*) AS n, SUM(c.code = 'emergency') AS emergency, SUM(s.is_paid = 1) AS paid, MAX(r.received_at) AS last_at,
                SUM(st.code IN ('done','closed')) AS completed, MIN(h.address) AS address
            " . self::FROM . " $w GROUP BY cl.id, cl.last_name, cl.first_name, cl.middle_name, cl.phone ORDER BY n DESC, cl.last_name", $p);
        $out = [];
        $total = 0;
        foreach ($rows as $x) {
            $total += (int)$x['n'];
            $out[] = [
                'client' => Part2Util::fullName($x, 'cl_'), 'phone' => $x['phone'], 'address' => $x['address'],
                'requests' => (int)$x['n'], 'emergency' => (int)$x['emergency'], 'paid' => (int)$x['paid'], 'completed' => (int)$x['completed'], 'last_at' => $x['last_at'],
            ];
        }
        return [
            'columns' => self::cols(['client' => 'Клиент', 'phone' => 'Телефон', 'address' => 'Адрес', 'requests' => 'Число обращений', 'emergency' => 'Аварийных',
                'paid' => 'Платных', 'completed' => 'Выполнено', 'last_at' => 'Последнее обращение']),
            'rows' => $out,
            'summary' => ['clients' => count($out), 'requests' => $total, 'avg_per_client' => $out ? round($total / count($out), 2) : 0],
            'summary_titles' => ['clients' => 'Клиентов', 'requests' => 'Обращений', 'avg_per_client' => 'Обращений на клиента'],
        ];
    }

    // ------------------------------------------------------------------ служебное

    /** Просрочка в минутах или null: открытая заявка после due_at либо выполненная позже срока. */
    private static function lateMinutes(string $statusCode, string $dueAt, ?string $completedAt, string $now): ?int
    {
        if (in_array($statusCode, ['rejected', 'cancelled'], true)) {
            return null;
        }
        if ($completedAt !== null) {
            return $completedAt > $dueAt ? Part2Util::minutesBetween($dueAt, $completedAt) : null;
        }
        return $dueAt < $now ? Part2Util::minutesBetween($dueAt, $now) : null;
    }

    private static function addr(string $address, ?string $premise): string
    {
        return $premise !== null && $premise !== '' ? "$address, кв. $premise" : $address;
    }

    private static function cols(array $m): array
    {
        $out = [];
        foreach ($m as $k => $t) {
            $out[] = ['key' => $k, 'title' => $t];
        }
        return $out;
    }

    private static function d($v, string $label): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = substr(trim((string)$v), 0, 10);
        $d = \DateTime::createFromFormat('Y-m-d', $s);
        if (!$d || $d->format('Y-m-d') !== $s) {
            throw HttpException::bad($label . ': некорректная дата (нужен формат ГГГГ-ММ-ДД)');
        }
        return $s;
    }

    /** @return array{0:string,1:array} WHERE и параметры по общим фильтрам (период по столбцу $dateCol). */
    private static function where(array $f, string $dateCol): array
    {
        $w = ["$dateCol >= ?", "$dateCol < ?"];
        $p = [$f['from'] . ' 00:00:00', date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'];
        if ($f['category'] !== null) {
            if (ctype_digit($f['category'])) {
                $w[] = 'c.id = ?';
                $p[] = (int)$f['category'];
            } else {
                $w[] = 'c.code = ?';
                $p[] = $f['category'];
            }
        }
        if ($f['status'] !== null) {
            if (ctype_digit($f['status'])) {
                $w[] = 'st.id = ?';
                $p[] = (int)$f['status'];
            } else {
                $w[] = 'st.code = ?';
                $p[] = $f['status'];
            }
        }
        if ($f['service'] !== null) {
            if (ctype_digit($f['service'])) {
                $w[] = 's.id = ?';
                $p[] = (int)$f['service'];
            } else {
                $w[] = 's.code = ?';
                $p[] = $f['service'];
            }
        }
        if ($f['employee'] !== null && ctype_digit($f['employee'])) {
            $w[] = 'r.assignee_id = ?';
            $p[] = (int)$f['employee'];
        }
        if ($f['house'] !== null && ctype_digit($f['house'])) {
            $w[] = 'r.house_id = ?';
            $p[] = (int)$f['house'];
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }

    // ------------------------------------------------------------------ форматы вывода

    public static function toCsv(array $rep): string
    {
        $fmt = static function ($v): string {
            if ($v === null) {
                return '';
            }
            if (is_float($v)) {
                $v = str_replace('.', ',', (string)$v);
            }
            $s = (string)$v;
            if ($s !== '' && in_array($s[0], ['=', '@', "\t", "\r"], true)) {
                $s = "'" . $s;
            }
            return '"' . str_replace('"', '""', $s) . '"';
        };
        $lines = [implode(';', array_map(fn($c) => $fmt($c['title']), $rep['columns']))];
        foreach ($rep['rows'] as $row) {
            $lines[] = implode(';', array_map(fn($c) => $fmt($row[$c['key']] ?? null), $rep['columns']));
        }
        $lines[] = '';
        foreach ($rep['summary'] as $k => $v) {
            $lines[] = $fmt($rep['summary_titles'][$k] ?? $k) . ';' . $fmt($v);
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    public static function toXlsx(array $rep): string
    {
        $headers = array_map(fn($c) => $c['title'], $rep['columns']);
        $rows = [];
        foreach ($rep['rows'] as $row) {
            $r = [];
            foreach ($rep['columns'] as $c) {
                $v = $row[$c['key']] ?? null;
                $r[] = is_int($v) || is_float($v) || $v === null ? $v : (string)$v;
            }
            $rows[] = $r;
        }
        $rows[] = [];
        foreach ($rep['summary'] as $k => $v) {
            $rows[] = [(string)($rep['summary_titles'][$k] ?? $k), $v];
        }
        return Xlsx::build($rep['title'], $headers, $rows);
    }

    /** «PDF»: печатная HTML-страница с print-CSS (в браузере - «Печать» / «Сохранить как PDF»). */
    public static function toHtml(array $rep, string $userName): string
    {
        $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $f = $rep['filters'];
        $period = date('d.m.Y', strtotime($f['from'])) . ' - ' . date('d.m.Y', strtotime($f['to']));
        $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>' . $h($rep['title']) . ' - ПосадДом</title><style>
body{font-family:Inter,Arial,sans-serif;font-size:12px;color:#000;margin:24px}
h1{font-size:20px;margin:0 0 4px}
.meta{color:#555;margin-bottom:14px}
table{border-collapse:collapse;width:100%}
th{background:#D9D9D9;text-align:left;font-size:12px}
th,td{border:1px solid #999;padding:4px 6px;vertical-align:top}
.sum{margin-top:14px}
.sum td{border:none;padding:2px 12px 2px 0}
.btn{margin-bottom:12px;padding:6px 14px;background:#2F80ED;color:#fff;border:0;border-radius:6px;cursor:pointer}
@media print{.btn{display:none}body{margin:0}@page{size:A4 landscape;margin:12mm}}
</style></head><body>';
        $html .= '<button class="btn" onclick="window.print()">Печать / сохранить как PDF</button>';
        $html .= '<h1>' . $h($rep['title']) . '</h1><div class="meta">Период: ' . $h($period) . '. Сформировано: ' . $h(date('d.m.Y H:i')) . ', ' . $h($userName) . '. ООО УК «Посадское обслуживание домов»</div>';
        $html .= '<table><thead><tr>';
        foreach ($rep['columns'] as $c) {
            $html .= '<th>' . $h($c['title']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rep['rows'] as $row) {
            $html .= '<tr>';
            foreach ($rep['columns'] as $c) {
                $html .= '<td>' . $h($row[$c['key']] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        if (!$rep['rows']) {
            $html .= '<tr><td colspan="' . count($rep['columns']) . '">Нет данных за выбранный период</td></tr>';
        }
        $html .= '</tbody></table><table class="sum">';
        foreach ($rep['summary'] as $k => $v) {
            $html .= '<tr><td><b>' . $h($rep['summary_titles'][$k] ?? $k) . ':</b></td><td>' . $h($v) . '</td></tr>';
        }
        return $html . '</table></body></html>';
    }
}
