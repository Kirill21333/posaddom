<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Part2Util as U;

/** Уведомления текущего сотрудника на сайте (channel = site). */
final class NotificationController
{
    public static function list(Request $r): array
    {
        $eid = U::me()['employee_id'];
        if (!$eid) {
            return ['items' => [], 'unread' => 0, 'total' => 0];
        }
        $where = "n.recipient_type = 'employee' AND n.employee_id = ? AND n.channel = 'site'";
        $params = [$eid];
        if ($r->q('unread') === '1') {
            $where .= ' AND n.read_at IS NULL';
        }
        $limit = max(1, min(200, $r->qint('limit', $r->qint('per_page', 50))));
        $rows = Db::all("SELECT n.id, n.type, n.message, n.request_id, rq.request_number, n.status, n.created_at, n.read_at
            FROM notifications n LEFT JOIN requests rq ON rq.id = n.request_id
            WHERE $where ORDER BY n.id DESC LIMIT $limit", $params);
        $items = array_map(fn($x) => [
            'id' => (int)$x['id'], 'type' => $x['type'], 'message' => $x['message'],
            'request_id' => $x['request_id'] === null ? null : (int)$x['request_id'], 'request_number' => $x['request_number'],
            'status' => $x['status'], 'is_read' => $x['read_at'] !== null ? 1 : 0, 'created_at' => $x['created_at'], 'read_at' => $x['read_at'],
        ], $rows);
        $unread = (int)Db::val("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'employee' AND employee_id = ? AND channel = 'site' AND read_at IS NULL", [$eid]);
        $total = (int)Db::val("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'employee' AND employee_id = ? AND channel = 'site'", [$eid]);
        return ['items' => $items, 'unread' => $unread, 'total' => $total];
    }

    public static function read(Request $r, array $p): array
    {
        $eid = U::me()['employee_id'];
        $id = (int)$p['id'];
        $row = $eid ? Db::one("SELECT id FROM notifications WHERE id = ? AND employee_id = ? AND channel = 'site'", [$id, $eid]) : null;
        if (!$row) {
            throw HttpException::notFound('Уведомление не найдено');
        }
        Db::exec("UPDATE notifications SET read_at = COALESCE(read_at, ?), status = 'read' WHERE id = ?", [now(), $id]);
        return ['id' => $id, 'is_read' => 1];
    }

    public static function readAll(Request $r): array
    {
        $eid = U::me()['employee_id'];
        $n = $eid ? Db::exec("UPDATE notifications SET read_at = ?, status = 'read' WHERE employee_id = ? AND channel = 'site' AND read_at IS NULL", [now(), $eid]) : 0;
        return ['updated' => $n];
    }
}
