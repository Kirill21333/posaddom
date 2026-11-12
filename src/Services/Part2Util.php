<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;

/** Общие помощники контроллеров второй части (услуги, сотрудники, клиенты, сводка, отчёты, администрирование). */
final class Part2Util
{
    public const OPEN_STATUSES = ['new', 'accepted', 'assigned', 'in_progress'];

    /** Текущий пользователь в нормализованном виде. */
    public static function me(): array
    {
        $u = Auth::requireLogin();
        $role = $u['role'] ?? (is_array($u['roles'] ?? null) ? ($u['roles'][0] ?? '') : '');
        return [
            'id' => (int)$u['id'],
            'login' => (string)($u['login'] ?? ''),
            'role' => (string)$role,
            'employee_id' => isset($u['employee_id']) && $u['employee_id'] ? (int)$u['employee_id'] : null,
            'name' => (string)($u['name'] ?? ''),
        ];
    }

    public static function role(): string
    {
        return self::me()['role'];
    }

    /** Проверка роли внутри метода (маршруты дополнительно защищены в routes_extra.php). */
    public static function requireRoles(array $roles): array
    {
        $me = self::me();
        if (!in_array($me['role'], $roles, true)) {
            throw HttpException::forbidden('Недостаточно прав для этого действия');
        }
        return $me;
    }

    /** Запись в журнал аудита. */
    public static function audit(string $action, ?string $entityType = null, ?int $entityId = null, $old = null, $new = null): void
    {
        $enc = static function ($v): ?string {
            if ($v === null) {
                return null;
            }
            $s = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            return mb_substr((string)$s, 0, 60000);
        };
        try {
            $uid = null;
            try {
                $uid = Auth::id() ?: null;
            } catch (\Throwable $e) {
                $uid = null;
            }
            Db::insert('audit_log', [
                'user_id' => $uid,
                'action' => mb_substr($action, 0, 50),
                'entity_type' => mb_substr((string)$entityType, 0, 40),
                'entity_id' => $entityId,
                'old_value' => $enc($old),
                'new_value' => $enc($new),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            if (class_exists(\App\Core\Log::class)) {
                \App\Core\Log::file('audit_log failed: ' . $e->getMessage());
            }
        }
    }

    public static function setting(string $key, string $default = ''): string
    {
        $v = Db::val('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
        return $v === null ? $default : (string)$v;
    }

    public static function fullName(array $row, string $p = ''): string
    {
        return trim(implode(' ', array_filter([$row[$p . 'last_name'] ?? '', $row[$p . 'first_name'] ?? '', $row[$p . 'middle_name'] ?? ''], fn($x) => $x !== '')));
    }

    /** «Смирнов А.В.» */
    public static function shortName(array $row, string $p = ''): string
    {
        $last = (string)($row[$p . 'last_name'] ?? '');
        $f = (string)($row[$p . 'first_name'] ?? '');
        $m = (string)($row[$p . 'middle_name'] ?? '');
        $s = $last;
        if ($f !== '') {
            $s .= ' ' . mb_substr($f, 0, 1) . '.';
        }
        if ($m !== '') {
            $s .= mb_substr($m, 0, 1) . '.';
        }
        return trim($s);
    }

    /** @return array{0:int,1:int,2:int} page, per_page, offset */
    public static function paging(Request $r, int $defPer = 50, int $maxPer = 200): array
    {
        $page = max(1, $r->qint('page', 1));
        $per = $r->qint('per_page', $defPer);
        $per = max(1, min($maxPer, $per));
        return [$page, $per, ($page - 1) * $per];
    }

    public static function flag($v): int
    {
        if (is_string($v)) {
            $v = strtolower(trim($v));
            return in_array($v, ['1', 'true', 'on', 'yes', 'да'], true) ? 1 : 0;
        }
        return $v ? 1 : 0;
    }

    public static function digits(string $s): string
    {
        return preg_replace('/\D+/', '', $s) ?? '';
    }

    /** Телефон -> последние 10 цифр (для сравнения +7 / 8). */
    public static function phoneKey(string $s): string
    {
        $d = self::digits($s);
        return strlen($d) > 10 ? substr($d, -10) : $d;
    }

    /** SQL-выражение «только цифры» для столбца телефона. */
    public static function sqlDigits(string $col): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col,' ',''),'-',''),'(',''),')',''),'+',''),'.','')";
    }

    public static function minutesText(int $min): string
    {
        $min = abs($min);
        if ($min >= 2880) {
            return intdiv($min, 1440) . ' дн.' . (($min % 1440) >= 60 ? ' ' . intdiv($min % 1440, 60) . ' ч' : '');
        }
        $h = intdiv($min, 60);
        $m = $min % 60;
        if ($h > 0 && $m > 0) {
            return "$h ч $m мин";
        }
        return $h > 0 ? "$h ч" : "$m мин";
    }

    public static function minutesBetween(string $from, string $to): int
    {
        return (int)floor((strtotime($to) - strtotime($from)) / 60);
    }

    /** Предельная нагрузка для отображения: общее правило (без фильтров) с минимальным приоритетом или настройка по умолчанию. */
    public static function maxLoad(): int
    {
        $v = Db::val('SELECT max_load FROM assignment_rules WHERE is_active = 1 AND request_category_id IS NULL AND service_category_id IS NULL AND section_id IS NULL ORDER BY priority, id LIMIT 1');
        if ($v !== null) {
            return (int)$v;
        }
        return (int)self::setting('default_max_load', '9');
    }

    /** Нагрузка исполнителей: [employee_id => ['load'=>Σвесов, 'open'=>число]] по заявкам assigned/in_progress. */
    public static function loads(?array $employeeIds = null): array
    {
        $sql = "SELECT r.assignee_id AS id, COALESCE(SUM(c.weight),0) AS l, COUNT(*) AS n
                FROM requests r
                JOIN request_categories c ON c.id = r.category_id
                JOIN request_statuses s ON s.id = r.status_id
                WHERE r.assignee_id IS NOT NULL AND s.code IN ('assigned','in_progress')";
        $p = [];
        if ($employeeIds !== null) {
            if (!$employeeIds) {
                return [];
            }
            $sql .= ' AND r.assignee_id IN (' . Db::in($employeeIds) . ')';
            $p = array_values($employeeIds);
        }
        $out = [];
        foreach (Db::all($sql . ' GROUP BY r.assignee_id', $p) as $row) {
            $out[(int)$row['id']] = ['load' => (int)$row['l'], 'open' => (int)$row['n']];
        }
        return $out;
    }

    /** Нормализует значение времени HH:MM(:SS) или null. */
    public static function time($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string)$v);
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $s, $m)) {
            throw HttpException::bad('Некорректное время: ' . $s . ' (нужен формат ЧЧ:ММ)');
        }
        return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)($m[3] ?? 0));
    }

    /** Нормализует дату ГГГГ-ММ-ДД или бросает 422. */
    public static function date($v, string $label = 'Дата'): ?string
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
}
