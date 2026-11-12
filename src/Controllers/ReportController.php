<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Raw;
use App\Core\Request;
use App\Services\Part2Util as U;
use App\Services\ReportService;

/** Отчёты: GET /api/reports и GET /api/reports/{requests|executors|paid|overdue|clients}?format=json|csv|xlsx|pdf */
final class ReportController
{
    /** Список отчётов, доступных текущей роли. */
    public static function types(Request $r): array
    {
        $role = U::role();
        $allowed = ReportService::ROLE_TYPES[$role] ?? [];
        $items = [];
        foreach ($allowed as $t) {
            $items[] = ['type' => $t, 'title' => ReportService::TYPES[$t]];
        }
        return ['items' => $items];
    }

    public static function get(Request $r, array $p)
    {
        $me = U::me();
        $type = (string)$p['type'];
        if (!isset(ReportService::TYPES[$type])) {
            throw HttpException::notFound('Неизвестный отчёт');
        }
        if (!in_array($type, ReportService::ROLE_TYPES[$me['role']] ?? [], true)) {
            throw HttpException::forbidden('Этот отчёт недоступен для вашей роли');
        }
        $format = strtolower((string)$r->q('format', 'json'));
        if (!in_array($format, ['json', 'csv', 'xlsx', 'pdf'], true)) {
            throw HttpException::bad('Формат: json, csv, xlsx или pdf');
        }
        $filters = ReportService::filters($r->query);
        $rep = ReportService::run($type, $filters);

        if ($format === 'json') {
            return $rep;
        }
        U::audit('report.export', 'report', null, null, ['report' => $type, 'format' => $format, 'filters' => $filters, 'rows' => count($rep['rows'])]);
        $base = 'report_' . $type . '_' . $filters['from'] . '_' . $filters['to'];
        switch ($format) {
            case 'csv':
                return new Raw('text/csv; charset=utf-8', ReportService::toCsv($rep), $base . '.csv');
            case 'xlsx':
                return new Raw('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ReportService::toXlsx($rep), $base . '.xlsx');
            default:
                return new Raw('text/html; charset=utf-8', ReportService::toHtml($rep, $me['name'] !== '' ? $me['name'] : $me['login']));
        }
    }
}
