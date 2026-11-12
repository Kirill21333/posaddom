<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Settings;
use App\Services\AssignmentService;

/** GET /api/ref: справочники для форм и фильтров. */
final class ReferenceController
{
    public static function ref(Request $r): array
    {
        $u = Auth::requireLogin();
        $out = [
            'categories' => Db::all('SELECT id, code, name, prefix, response_minutes, response_work_days, resolve_hours, weight FROM request_categories ORDER BY id'),
            'statuses' => Db::all('SELECT id, code, name, is_final, sort_order FROM request_statuses ORDER BY sort_order'),
            'channels' => Db::all('SELECT id, code, name FROM request_channels ORDER BY id'),
            'houses' => Db::all('SELECT id, address, section_id FROM houses WHERE is_active = 1 ORDER BY address'),
            'service_categories' => Db::all('SELECT id, parent_id, name FROM service_categories ORDER BY id'),
            'positions' => Db::all('SELECT id, name, is_executor FROM positions ORDER BY id'),
            'employee_statuses' => Db::all('SELECT id, code, name FROM employee_statuses ORDER BY id'),
            'schedule_types' => Db::all('SELECT id, code, name FROM schedule_types ORDER BY id'),
            'client_statuses' => Db::all('SELECT id, code, name FROM client_statuses ORDER BY id'),
            'sections' => Db::all('SELECT id, name, master_id FROM sections ORDER BY id'),
            'roles' => Db::all('SELECT id, code, name FROM roles ORDER BY id'),
            'settings' => [
                'business_start' => Settings::get('business_start', '09:00'), 'business_end' => Settings::get('business_end', '18:00'),
                'warn_percent' => Settings::int('warn_percent', 25), 'photo_max_mb' => Settings::int('photo_max_mb', 10),
            ],
        ];
        if ($u['role'] !== 'employee') {
            $out['executors'] = array_map(fn($e) => [
                'id' => (int)$e['id'], 'full_name' => trim("{$e['last_name']} {$e['first_name']} {$e['middle_name']}"),
                'short_name' => AssignmentService::short($e), 'position' => $e['position'], 'status' => $e['status_code'],
            ], Db::all('SELECT e.id, e.last_name, e.first_name, e.middle_name, p.name AS position, st.code AS status_code
                        FROM employees e JOIN positions p ON p.id = e.position_id AND p.is_executor = 1
                        JOIN employee_statuses st ON st.id = e.status_id ORDER BY e.last_name, e.first_name'));
        }
        return $out;
    }
}
