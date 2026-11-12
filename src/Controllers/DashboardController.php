<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Services\DashboardService;

/** GET /api/dashboard?period=today|week|month|quarter&house= */
final class DashboardController
{
    public static function get(Request $r): array
    {
        $period = $r->q('period', 'month');
        if (!in_array($period, DashboardService::PERIODS, true)) {
            throw HttpException::bad('Период: today, week, month или quarter');
        }
        $limit = max(1, min(100, $r->qint('limit', 20)));
        return DashboardService::build($period, $r->qint('house'), $limit);
    }
}
