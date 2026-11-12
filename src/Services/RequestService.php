<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use DateTimeImmutable;

/** Заявки: список, карточка, создание, правка, статусы, назначение, комментарии (ТЗ 7.2, 7.6, 7.7). */
final class RequestService
{
    public const STAFF = ['dispatcher', 'manager', 'admin'];
    /** Матрица переходов статусов (назначение «assigned» выполняется только через AssignmentService). */
    public const TRANSITIONS = [
        'new' => ['accepted', 'rejected', 'cancelled'],
        'accepted' => ['assigned', 'rejected', 'cancelled'],
        'assigned' => ['in_progress', 'cancelled'],
        'in_progress' => ['done', 'cancelled'],
        'done' => ['closed'],
    ];
    private const OPEN = "('new','accepted','assigned','in_progress')";

    private const SELECT = "SELECT r.id, r.request_number, r.received_at, r.arrive_due, r.due_at, r.warn_at, r.arrived_at, r.completed_at, r.closed_at,
        r.is_overdue, r.manual_assign_required, r.description, r.assignee_id, r.created_by, r.channel_id,
        c.code AS category_code, c.name AS category_name, c.weight AS category_weight, st.code AS status_code, st.name AS status_name,
        h.id AS house_id, h.address AS house_address, pr.id AS premise_id, pr.number AS premise_number, pr.type AS premise_type,
        sv.id AS service_id, sv.code AS service_code, sv.name AS service_name, sv.is_paid AS service_paid, sv.price AS service_price,
        sv.basis AS service_basis, sv.norm_document AS service_norm, sv.unit AS service_unit,
        cl.id AS client_id, cl.last_name AS cl_last, cl.first_name AS cl_first, cl.middle_name AS cl_mid, cl.phone AS cl_phone, cl.email AS cl_email,
        cs.code AS cl_status_code, cs.name AS cl_status_name,
        e.id AS emp_id, e.last_name AS e_last, e.first_name AS e_first, e.middle_name AS e_mid, p.name AS e_position,
        ch.code AS channel_code, ch.name AS channel_name
      FROM requests r
      JOIN request_categories c ON c.id = r.category_id
      JOIN request_statuses st ON st.id = r.status_id
      JOIN houses h ON h.id = r.house_id
      LEFT JOIN premises pr ON pr.id = r.premise_id
      JOIN services sv ON sv.id = r.service_id
      JOIN clients cl ON cl.id = r.client_id
      JOIN client_statuses cs ON cs.id = cl.status_id
      JOIN request_channels ch ON ch.id = r.channel_id
      LEFT JOIN employees e ON e.id = r.assignee_id
      LEFT JOIN positions p ON p.id = e.position_id";

    // ------------------------------------------------------------------ список

    public static function list(array $q, array $user): array
    {
        [$scope, $sp] = self::scope($user);
        [$where, $params] = [$scope, $sp];
        $nowS = now();
        if (!empty($q['status'])) {
            // один код или список через запятую: status=new,accepted
            $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$q['status'])), 'strlen')));
            $known = array_column(Db::all('SELECT code FROM request_statuses'), 'code');
            foreach ($codes as $code) {
                if (!in_array($code, $known, true)) {
                    throw HttpException::bad("Неизвестный статус «{$code}»", ['status' => 'Недопустимое значение']);
                }
            }
            if ($codes) {
                $where .= ' AND st.code IN (' . Db::in($codes) . ')';
                array_push($params, ...$codes);
            }
        }
        if (!empty($q['category'])) {
            $where .= ' AND c.code = ?';
            $params[] = (string)$q['category'];
        }
        if (!empty($q['employee'])) {
            if ($q['employee'] === 'none') {
                $where .= ' AND r.assignee_id IS NULL';
            } else {
                $where .= ' AND r.assignee_id = ?';
                $params[] = (int)$q['employee'];
            }
        }
        if (!empty($q['house'])) {
            $where .= ' AND r.house_id = ?';
            $params[] = (int)$q['house'];
        }
        if (!empty($q['service'])) {
            $where .= ' AND r.service_id = ?';
            $params[] = (int)$q['service'];
        }
        if (!empty($q['overdue'])) {
            $where .= ' AND st.code IN ' . self::OPEN . ' AND r.due_at < ?';
            $params[] = $nowS;
        }
        if (!empty($q['queue'])) {
            $where .= ' AND r.manual_assign_required = 1 AND r.assignee_id IS NULL AND st.is_final = 0';
        }
        if (!empty($q['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$q['from'])) {
            $where .= ' AND r.received_at >= ?';
            $params[] = $q['from'] . ' 00:00:00';
        }
        if (!empty($q['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$q['to'])) {
            $where .= ' AND r.received_at <= ?';
            $params[] = $q['to'] . ' 23:59:59';
        }
        if (isset($q['q']) && trim((string)$q['q']) !== '') {
            $s = '%' . trim((string)$q['q']) . '%';
            $digits = preg_replace('/\D+/', '', (string)$q['q']);
            $where .= " AND (r.request_number LIKE ? OR CONCAT(cl.last_name, ' ', cl.first_name, ' ', COALESCE(cl.middle_name, '')) LIKE ? OR h.address LIKE ? OR sv.name LIKE ?";
            array_push($params, $s, $s, $s, $s);
            if (strlen($digits) >= 4) {
                $where .= " OR REPLACE(REPLACE(REPLACE(REPLACE(cl.phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?";
                $params[] = '%' . $digits . '%';
            }
            $where .= ')';
        }
        $order = ($q['sort'] ?? 'deadline') === 'received'
            ? 'r.received_at DESC, r.id DESC'
            : "st.is_final ASC, (st.code = 'done') ASC, r.due_at ASC, r.id ASC";
        $perPage = max(1, min(200, (int)($q['per_page'] ?? 50)));
        $page = max(1, (int)($q['page'] ?? 1));
        $from = ' FROM requests r JOIN request_categories c ON c.id = r.category_id JOIN request_statuses st ON st.id = r.status_id
                  JOIN houses h ON h.id = r.house_id JOIN services sv ON sv.id = r.service_id JOIN clients cl ON cl.id = r.client_id';
        $total = (int)Db::val("SELECT COUNT(*) $from WHERE $where", $params);
        $ids = array_column(Db::all("SELECT r.id $from WHERE $where ORDER BY $order LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params), 'id');
        $items = [];
        if ($ids) {
            $rows = [];
            foreach (Db::all(self::SELECT . ' WHERE r.id IN (' . Db::in($ids) . ')', $ids) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            foreach ($ids as $id) {
                $items[] = self::item($rows[(int)$id]);
            }
        }
        return ['items' => $items, 'total' => $total, 'counts' => self::counts($scope, $sp), 'page' => $page, 'per_page' => $perPage];
    }

    /** Счётчики вкладок списка (в пределах видимости пользователя, без учёта фильтров). */
    public static function counts(string $scope, array $sp): array
    {
        $r = Db::one("SELECT COUNT(*) AS `all`,
                SUM(st.code IN ('new','accepted')) AS new_cnt,
                SUM(r.manual_assign_required = 1 AND r.assignee_id IS NULL AND st.is_final = 0) AS queue_cnt,
                SUM(st.code IN ('assigned','in_progress')) AS in_work,
                SUM(st.code IN " . self::OPEN . " AND r.due_at < ?) AS overdue_cnt,
                SUM(st.code = 'closed') AS closed_cnt
              FROM requests r JOIN request_statuses st ON st.id = r.status_id WHERE $scope", array_merge([now()], $sp));
        return ['all' => (int)$r['all'], 'new' => (int)$r['new_cnt'], 'queue' => (int)$r['queue_cnt'],
                'in_work' => (int)$r['in_work'], 'overdue' => (int)$r['overdue_cnt'], 'closed' => (int)$r['closed_cnt']];
    }

    /** Условие видимости: исполнитель видит только назначенные ему заявки. */
    private static function scope(array $user): array
    {
        if ($user['role'] === 'employee') {
            return ['r.assignee_id = ?', [(int)($user['employee_id'] ?? 0)]];
        }
        return ['1 = 1', []];
    }

    // ------------------------------------------------------------------ карточка

    private static function fetch(int $id): array
    {
        $r = Db::one(self::SELECT . ' WHERE r.id = ?', [$id]);
        if (!$r) {
            throw HttpException::notFound('Заявка не найдена');
        }
        return $r;
    }

    private static function assertAccess(array $r, array $user): void
    {
        if ($user['role'] === 'employee' && (int)($r['assignee_id'] ?? 0) !== (int)($user['employee_id'] ?? -1)) {
            throw HttpException::forbidden('Заявка не назначена вам');
        }
    }

    /** Заявка с проверкой доступа (для контроллеров фото и др.). */
    public static function accessible(int $id, array $user): array
    {
        $r = self::fetch($id);
        self::assertAccess($r, $user);
        return $r;
    }

    public static function item(array $r, ?DateTimeImmutable $now = null): array
    {
        [$overdue, $minutes] = self::overdue($r, $now);
        return [
            'id' => (int)$r['id'], 'number' => $r['request_number'], 'received_at' => $r['received_at'],
            'category' => ['code' => $r['category_code'], 'name' => $r['category_name']],
            'house' => ['id' => (int)$r['house_id'], 'address' => $r['house_address']],
            'premise' => $r['premise_id'] !== null ? ['id' => (int)$r['premise_id'], 'number' => $r['premise_number']] : null,
            'service' => ['id' => (int)$r['service_id'], 'code' => $r['service_code'], 'name' => $r['service_name'], 'is_paid' => (bool)$r['service_paid']],
            'client' => ['id' => (int)$r['client_id'], 'full_name' => self::full($r['cl_last'], $r['cl_first'], $r['cl_mid']), 'phone' => $r['cl_phone']],
            'assignee' => self::assignee($r),
            'status' => ['code' => $r['status_code'], 'name' => $r['status_name']],
            'arrive_due' => $r['arrive_due'], 'due_at' => $r['due_at'],
            'is_overdue' => $overdue, 'manual_assign_required' => (bool)$r['manual_assign_required'],
            'overdue_minutes' => $minutes,
        ];
    }

    /** @return array{0:bool,1:?int} признак просрочки и её величина в минутах */
    public static function overdue(array $r, ?DateTimeImmutable $now = null): array
    {
        if (in_array($r['status_code'], ['rejected', 'cancelled'], true)) {
            return [false, null];
        }
        $m = DeadlineService::overdueMinutes($r['due_at'], $r['completed_at'] ?? null, $now);
        return [$m !== null || (bool)$r['is_overdue'], $m];
    }

    private static function assignee(array $r): ?array
    {
        if ($r['emp_id'] === null) {
            return null;
        }
        return ['id' => (int)$r['emp_id'], 'full_name' => self::full($r['e_last'], $r['e_first'], $r['e_mid']),
                'short_name' => AssignmentService::short(['last_name' => $r['e_last'], 'first_name' => $r['e_first'], 'middle_name' => $r['e_mid']]),
                'position' => $r['e_position']];
    }

    private static function full(?string $l, ?string $f, ?string $m): string
    {
        return trim("$l $f $m");
    }

    public static function card(int $id, array $user): array
    {
        $r = self::fetch($id);
        self::assertAccess($r, $user);
        return self::buildCard($r, $user);
    }

    private static function buildCard(array $r, array $user): array
    {
        $id = (int)$r['id'];
        $card = self::item($r);
        $card['description'] = $r['description'];
        $card['channel'] = ['code' => $r['channel_code'], 'name' => $r['channel_name']];
        $card['client'] += ['email' => $r['cl_email'], 'status' => ['code' => $r['cl_status_code'], 'name' => $r['cl_status_name']]];
        if ($card['premise']) {
            $card['premise']['type'] = $r['premise_type'];
        }
        $card['service'] += ['price' => $r['service_price'] !== null ? (float)$r['service_price'] : null,
                             'basis' => $r['service_basis'], 'norm_document' => $r['service_norm'], 'unit' => $r['service_unit']];
        $card['deadlines'] = ['arrive_due' => $r['arrive_due'], 'due_at' => $r['due_at'], 'warn_at' => $r['warn_at'],
                              'arrived_at' => $r['arrived_at'], 'completed_at' => $r['completed_at'], 'closed_at' => $r['closed_at']];
        $card['history'] = array_map(fn($h) => [
            'status' => $h['code'], 'status_name' => $h['name'], 'user_name' => $h['uname'] ?? 'система',
            'at' => $h['changed_at'], 'comment' => $h['comment'],
        ], Db::all('SELECT s.code, s.name, h.changed_at, h.comment, ' . self::USER_NAME . ' AS uname
                    FROM request_status_history h JOIN request_statuses s ON s.id = h.status_id
                    LEFT JOIN users u ON u.id = h.changed_by LEFT JOIN employees ue ON ue.id = u.employee_id
                    WHERE h.request_id = ? ORDER BY h.changed_at, h.id', [$id]));
        $card['comments'] = array_map(fn($c) => [
            'id' => (int)$c['id'], 'author_name' => $c['uname'], 'text' => $c['text'],
            'is_additional_contact' => (bool)$c['is_additional_contact'], 'created_at' => $c['created_at'],
        ], Db::all('SELECT c.id, c.text, c.is_additional_contact, c.created_at, ' . self::USER_NAME . ' AS uname
                    FROM request_comments c JOIN users u ON u.id = c.author_id LEFT JOIN employees ue ON ue.id = u.employee_id
                    WHERE c.request_id = ? ORDER BY c.created_at, c.id', [$id]));
        $card['photos'] = array_map(fn($p) => [
            'id' => (int)$p['id'], 'stage' => $p['stage'], 'file_name' => $p['file_name'], 'url' => '/api/photos/' . $p['id'],
            'uploaded_by_name' => $p['uname'], 'uploaded_at' => $p['uploaded_at'], 'retention_until' => $p['retention_until'],
        ], Db::all('SELECT p.id, p.stage, p.file_name, p.uploaded_at, p.retention_until, ' . self::USER_NAME . ' AS uname
                    FROM request_photos p JOIN users u ON u.id = p.uploaded_by LEFT JOIN employees ue ON ue.id = u.employee_id
                    WHERE p.request_id = ? ORDER BY p.uploaded_at, p.id', [$id]));
        $card['assignments'] = array_map(fn($a) => [
            'id' => (int)$a['id'],
            'employee' => ['id' => (int)$a['employee_id'], 'full_name' => self::full($a['last_name'], $a['first_name'], $a['middle_name']),
                           'short_name' => AssignmentService::short($a)],
            'assigned_by_name' => $a['uname'], 'mode' => $a['mode'], 'off_schedule' => (bool)$a['off_schedule'],
            'assigned_at' => $a['assigned_at'], 'unassigned_at' => $a['unassigned_at'], 'reason' => $a['reason'],
        ], Db::all('SELECT a.*, e.last_name, e.first_name, e.middle_name, ' . self::USER_NAME . ' AS uname
                    FROM request_assignments a JOIN employees e ON e.id = a.employee_id
                    LEFT JOIN users u ON u.id = a.assigned_by LEFT JOIN employees ue ON ue.id = u.employee_id
                    WHERE a.request_id = ? ORDER BY a.assigned_at, a.id', [$id]));
        $ch = Db::one('SELECT id, amount, basis, status, created_at FROM charges WHERE request_id = ?', [$id]);
        $card['charge'] = $ch ? ['id' => (int)$ch['id'], 'amount' => (float)$ch['amount'], 'basis' => $ch['basis'], 'status' => $ch['status'], 'created_at' => $ch['created_at']] : null;
        $card['created_by_name'] = Db::val('SELECT ' . self::USER_NAME . ' FROM users u LEFT JOIN employees ue ON ue.id = u.employee_id WHERE u.id = ?', [(int)$r['created_by']]);
        $card['allowed_actions'] = self::allowedActions($r, $user);
        return $card;
    }

    /** Фрагмент SQL: «Морозова Е.В.» либо логин; таблицы u (users) и ue (employees). */
    private const USER_NAME = "COALESCE(CONCAT(ue.last_name, ' ', LEFT(ue.first_name, 1), '.', IF(ue.middle_name IS NULL OR ue.middle_name = '', '', CONCAT(LEFT(ue.middle_name, 1), '.'))), u.login)";

    /** Допустимые действия пользователя над заявкой (edit, status:*, assign, comment, photo, comment_additional). */
    public static function allowedActions(array $r, array $user): array
    {
        $role = $user['role'];
        $s = $r['status_code'];
        $closed = $s === 'closed';
        $final = in_array($s, ['closed', 'rejected', 'cancelled'], true);
        $staff = in_array($role, self::STAFF, true);
        $mine = $role === 'employee' && (int)($r['assignee_id'] ?? 0) === (int)($user['employee_id'] ?? -1);
        $a = [];
        if (!$staff && !$mine) {
            return $a;
        }
        if ($staff && !$final) {
            $a[] = 'edit';
        }
        foreach (self::TRANSITIONS[$s] ?? [] as $to) {
            if ($to === 'assigned') {
                continue;
            }
            if (self::canTransition($role, $to, $mine)) {
                $a[] = 'status:' . $to;
            }
        }
        if ($staff && in_array($s, ['new', 'accepted', 'assigned', 'in_progress'], true)) {
            $a[] = 'assign';
        }
        if ($closed) {
            $a[] = 'comment_additional';
        } else {
            $a[] = 'comment';
            if (!$final) {
                $a[] = 'photo';
            }
        }
        return $a;
    }

    private static function canTransition(string $role, string $to, bool $mine): bool
    {
        if ($role === 'employee') {
            return $mine && in_array($to, ['in_progress', 'done'], true);
        }
        return in_array($role, self::STAFF, true);
    }

    // ------------------------------------------------------------------ предпросмотр и создание

    public static function preview(array $in): array
    {
        $cat = self::category((string)($in['category_code'] ?? ''));
        $received = self::receivedAt($in['received_at'] ?? null);
        $dl = DeadlineService::calculate($cat, $received);
        $assignee = null;
        $queued = false;
        if (!empty($in['house_id']) && !empty($in['service_id'])) {
            $c = AssignmentService::pick(['category_id' => (int)$cat['id'], 'service_id' => (int)$in['service_id'], 'house_id' => (int)$in['house_id']]);
            if ($c) {
                $assignee = ['id' => $c['id'], 'full_name' => $c['full_name'], 'short_name' => $c['short_name'], 'position' => $c['position'], 'off_schedule' => $c['off_schedule']];
            } else {
                $queued = true;
            }
        }
        return ['number' => NumberingService::peek($cat['prefix'], (int)$received->format('Y')),
                'arrive_due' => $dl['arrive_due'], 'due_at' => $dl['due_at'], 'warn_at' => $dl['warn_at'],
                'assignee' => $assignee, 'queued' => $queued];
    }

    public static function create(array $in, array $user): array
    {
        $cat = self::category((string)($in['category_code'] ?? ''));
        $client = Db::one('SELECT id FROM clients WHERE id = ?', [(int)($in['client_id'] ?? 0)]);
        if (!$client) {
            throw HttpException::bad('Выберите клиента из справочника или зарегистрируйте нового', ['client_id' => 'Клиент не выбран']);
        }
        $house = Db::one('SELECT id, is_active FROM houses WHERE id = ?', [(int)($in['house_id'] ?? 0)]);
        if (!$house || !(int)$house['is_active']) {
            throw HttpException::bad('Выберите дом из списка', ['house_id' => 'Дом не выбран']);
        }
        $premiseId = !empty($in['premise_id']) ? (int)$in['premise_id'] : null;
        self::checkPremise($premiseId, (int)$house['id']);
        $service = self::activeService((int)($in['service_id'] ?? 0));
        $channel = Db::one('SELECT id, code FROM request_channels WHERE code = ?', [(string)($in['channel_code'] ?? 'phone')]);
        if (!$channel) {
            throw HttpException::bad('Неизвестный канал обращения', ['channel_code' => 'Недопустимое значение']);
        }
        $desc = trim((string)($in['description'] ?? ''));
        if ($desc === '') {
            throw HttpException::bad('Опишите проблему', ['description' => 'Обязательное поле']);
        }
        if (mb_strlen($desc) > 5000) {
            throw HttpException::bad('Описание не более 5000 символов', ['description' => 'Слишком длинное описание']);
        }
        $received = self::receivedAt($in['received_at'] ?? null);
        $dl = DeadlineService::calculate($cat, $received);
        $statusIds = array_column(Db::all('SELECT code, id FROM request_statuses'), 'id', 'code');

        $res = Db::tx(function () use ($in, $user, $cat, $client, $house, $premiseId, $service, $channel, $desc, $received, $dl, $statusIds) {
            $number = NumberingService::next($cat['prefix'], (int)$received->format('Y'));
            $id = Db::insert('requests', [
                'request_number' => $number, 'received_at' => $received->format('Y-m-d H:i:s'),
                'client_id' => (int)$client['id'], 'house_id' => (int)$house['id'], 'premise_id' => $premiseId,
                'category_id' => (int)$cat['id'], 'service_id' => (int)$service['id'], 'channel_id' => (int)$channel['id'],
                'status_id' => (int)$statusIds['new'], 'description' => $desc, 'created_by' => $user['id'],
                'arrive_due' => $dl['arrive_due'], 'due_at' => $dl['due_at'], 'warn_at' => $dl['warn_at'], 'updated_at' => now(),
            ]);
            Db::insert('request_status_history', ['request_id' => $id, 'status_id' => (int)$statusIds['new'], 'changed_by' => $user['id'], 'changed_at' => now(), 'comment' => null]);
            self::transition($id, 'accepted', $user['id'], null);
            NotificationService::client((int)$client['id'], 'request_registered',
                "ПосадДом: заявка $number принята. Срок устранения: " . date('d.m.Y H:i', strtotime($dl['due_at'])) . '.', $id);
            if ($cat['code'] === 'emergency') {
                IntegrationService::enqueue('rso', $id, ['event' => 'emergency_request', 'number' => $number, 'house_id' => (int)$house['id'],
                    'service' => $service['code'], 'received_at' => $received->format('Y-m-d H:i:s'), 'description' => $desc]);
            }
            if ($channel['code'] === 'gis_gkh') {
                IntegrationService::enqueue('gis_gkh', $id, ['event' => 'request_accepted', 'number' => $number]);
            }
            $assignee = AssignmentService::autoAssign($id, null);
            Log::audit('request.create', 'request', $id, null, ['number' => $number, 'category' => $cat['code'], 'service_id' => (int)$service['id'], 'house_id' => (int)$house['id']], $user['id']);
            return ['id' => $id, 'number' => $number, 'arrive_due' => $dl['arrive_due'], 'due_at' => $dl['due_at'],
                    'assignee' => $assignee ? ['id' => $assignee['employee_id'], 'short_name' => $assignee['short_name'], 'off_schedule' => $assignee['off_schedule']] : null,
                    'queued' => $assignee === null];
        });
        if ($res['assignee']) {
            $res['assignee'] = self::assignee(self::fetch($res['id']));
            $res['assignee']['off_schedule'] = (bool)Db::val('SELECT off_schedule FROM request_assignments WHERE request_id = ? AND unassigned_at IS NULL', [$res['id']]);
        }
        return $res;
    }

    private static function category(string $code): array
    {
        $cat = Db::one('SELECT * FROM request_categories WHERE code = ?', [$code]);
        if (!$cat) {
            throw HttpException::bad('Выберите категорию заявки', ['category_code' => 'Недопустимое значение']);
        }
        return $cat;
    }

    private static function activeService(int $id): array
    {
        $s = Db::one('SELECT id, code, is_archived, is_paid, price FROM services WHERE id = ?', [$id]);
        if (!$s) {
            throw HttpException::bad('Выберите услугу из справочника', ['service_id' => 'Услуга не выбрана']);
        }
        if ((int)$s['is_archived']) {
            throw HttpException::bad('Услуга переведена в архив и недоступна для новых заявок', ['service_id' => 'Услуга в архиве']);
        }
        return $s;
    }

    private static function checkPremise(?int $premiseId, int $houseId): void
    {
        if ($premiseId === null) {
            return;
        }
        $h = Db::val('SELECT house_id FROM premises WHERE id = ?', [$premiseId]);
        if ($h === null || (int)$h !== $houseId) {
            throw HttpException::bad('Помещение не принадлежит выбранному дому', ['premise_id' => 'Помещение не из этого дома']);
        }
    }

    /** Время поступления: по умолчанию «сейчас»; диспетчер может зарегистрировать обращение задним числом (до 30 суток). */
    private static function receivedAt($v): DateTimeImmutable
    {
        if ($v === null || $v === '') {
            return new DateTimeImmutable();
        }
        $ts = strtotime((string)$v);
        if ($ts === false) {
            throw HttpException::bad('Некорректная дата поступления', ['received_at' => 'Формат: ГГГГ-ММ-ДД ЧЧ:ММ']);
        }
        if ($ts > time() + 300) {
            throw HttpException::bad('Дата поступления не может быть в будущем', ['received_at' => 'Дата в будущем']);
        }
        if ($ts < time() - 30 * 86400) {
            throw HttpException::bad('Дата поступления не может быть старше 30 суток', ['received_at' => 'Слишком давно']);
        }
        return (new DateTimeImmutable())->setTimestamp($ts);
    }

    // ------------------------------------------------------------------ правка

    public static function update(int $id, array $in, array $user): array
    {
        if (!in_array($user['role'], self::STAFF, true)) {
            throw HttpException::forbidden();
        }
        return Db::tx(function () use ($id, $in, $user) {
            $r = Db::one(self::SELECT . ' WHERE r.id = ? FOR UPDATE', [$id]);
            if (!$r) {
                throw HttpException::notFound('Заявка не найдена');
            }
            if (in_array($r['status_code'], ['closed', 'rejected', 'cancelled'], true)) {
                throw HttpException::conflict($r['status_code'] === 'closed' ? 'Закрытая заявка не подлежит редактированию' : 'Заявку в конечном статусе редактировать нельзя');
            }
            $upd = [];
            $houseId = isset($in['house_id']) ? (int)$in['house_id'] : (int)$r['house_id'];
            $premiseId = array_key_exists('premise_id', $in) ? ($in['premise_id'] ? (int)$in['premise_id'] : null) : ($r['premise_id'] !== null ? (int)$r['premise_id'] : null);
            if (isset($in['house_id']) && $houseId !== (int)$r['house_id']) {
                if (!Db::val('SELECT is_active FROM houses WHERE id = ?', [$houseId])) {
                    throw HttpException::bad('Дом не найден или не обслуживается', ['house_id' => 'Дом не найден']);
                }
                $upd['house_id'] = $houseId;
                if (!array_key_exists('premise_id', $in)) {
                    $premiseId = null;
                }
            }
            self::checkPremise($premiseId, $houseId);
            if ($premiseId !== ($r['premise_id'] !== null ? (int)$r['premise_id'] : null)) {
                $upd['premise_id'] = $premiseId;
            }
            if (isset($in['service_id']) && (int)$in['service_id'] !== (int)$r['service_id']) {
                $upd['service_id'] = (int)self::activeService((int)$in['service_id'])['id'];
            }
            if (isset($in['client_id']) && (int)$in['client_id'] !== (int)$r['client_id']) {
                if (!Db::val('SELECT 1 FROM clients WHERE id = ?', [(int)$in['client_id']])) {
                    throw HttpException::bad('Клиент не найден', ['client_id' => 'Клиент не найден']);
                }
                $upd['client_id'] = (int)$in['client_id'];
            }
            if (isset($in['channel_code']) && $in['channel_code'] !== $r['channel_code']) {
                $chId = Db::val('SELECT id FROM request_channels WHERE code = ?', [(string)$in['channel_code']]);
                if (!$chId) {
                    throw HttpException::bad('Неизвестный канал обращения', ['channel_code' => 'Недопустимое значение']);
                }
                $upd['channel_id'] = (int)$chId;
            }
            if (isset($in['description'])) {
                $d = trim((string)$in['description']);
                if ($d === '' || mb_strlen($d) > 5000) {
                    throw HttpException::bad('Описание: от 1 до 5000 символов', ['description' => 'Некорректное описание']);
                }
                if ($d !== $r['description']) {
                    $upd['description'] = $d;
                }
            }
            if (isset($in['category_code']) && $in['category_code'] !== $r['category_code']) {
                $cat = self::category((string)$in['category_code']);
                $dl = DeadlineService::calculate($cat, new DateTimeImmutable($r['received_at']));
                $upd += ['category_id' => (int)$cat['id'], 'arrive_due' => $dl['arrive_due'], 'due_at' => $dl['due_at'], 'warn_at' => $dl['warn_at'], 'warning_sent' => 0, 'is_overdue' => 0];
            }
            if ($upd) {
                $old = array_intersect_key($r, array_flip(['house_id', 'premise_id', 'service_id', 'client_id', 'description']))
                    + ['category' => $r['category_code'], 'channel' => $r['channel_code'], 'due_at' => $r['due_at']];
                $upd['updated_at'] = now();
                Db::update('requests', $upd, 'id = ?', [$id]);
                Log::audit('request.update', 'request', $id, $old, $upd, $user['id']);
            }
            return self::buildCard(self::fetch($id), $user);
        });
    }

    // ------------------------------------------------------------------ статусы

    /** Низкоуровневая смена статуса: поля заявки, история, синхронизация с ГИС ЖКХ. Права и матрицу не проверяет. */
    public static function transition(int $id, string $to, ?int $userId, ?string $comment = null): void
    {
        $statusId = (int)Db::val('SELECT id FROM request_statuses WHERE code = ?', [$to]);
        $set = ['status_id' => $statusId, 'updated_at' => now()];
        $r = Db::one('SELECT due_at, arrived_at, channel_id, request_number FROM requests WHERE id = ?', [$id]);
        if ($to === 'in_progress' && $r['arrived_at'] === null) {
            $set['arrived_at'] = now();
        }
        if ($to === 'done') {
            $set['completed_at'] = now();
            if (strtotime($r['due_at']) < time()) {
                $set['is_overdue'] = 1;
            }
        }
        if ($to === 'closed') {
            $set['closed_at'] = now();
        }
        Db::update('requests', $set, 'id = ?', [$id]);
        if (in_array($to, ['cancelled', 'rejected'], true)) {
            Db::exec('UPDATE request_assignments SET unassigned_at = ? WHERE request_id = ? AND unassigned_at IS NULL', [now(), $id]);
            Db::exec('UPDATE requests SET manual_assign_required = 0 WHERE id = ?', [$id]);
        }
        Db::insert('request_status_history', ['request_id' => $id, 'status_id' => $statusId, 'changed_by' => $userId, 'changed_at' => now(),
            'comment' => $comment !== null ? mb_substr($comment, 0, 255) : null]);
        if ((string)Db::val('SELECT code FROM request_channels WHERE id = ?', [(int)$r['channel_id']]) === 'gis_gkh') {
            IntegrationService::enqueue('gis_gkh', $id, ['event' => 'status_changed', 'number' => $r['request_number'], 'status' => $to]);
        }
    }

    public static function changeStatus(int $id, string $to, ?string $comment, array $user): array
    {
        return Db::tx(function () use ($id, $to, $comment, $user) {
            $r = Db::one(self::SELECT . ' WHERE r.id = ? FOR UPDATE', [$id]);
            if (!$r) {
                throw HttpException::notFound('Заявка не найдена');
            }
            self::assertAccess($r, $user);
            $role = $user['role'];
            $mine = $role === 'employee' && (int)$r['assignee_id'] === (int)$user['employee_id'];
            $from = $r['status_code'];
            if (!Db::val('SELECT 1 FROM request_statuses WHERE code = ?', [$to])) {
                throw HttpException::bad('Неизвестный статус', ['status' => 'Недопустимое значение']);
            }
            if ($to === 'assigned') {
                throw HttpException::bad('Исполнитель назначается через «Назначить / Переназначить»');
            }
            if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw HttpException::bad('Недопустимый переход статуса: «' . $r['status_name'] . '» -> «' . self::statusName($to) . '»');
            }
            if (!self::canTransition($role, $to, $mine)) {
                throw HttpException::forbidden('Недостаточно прав для смены статуса на «' . self::statusName($to) . '»');
            }
            $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;
            if ($to === 'closed' && !Db::val("SELECT 1 FROM request_photos WHERE request_id = ? AND stage = 'final' LIMIT 1", [$id])) {
                throw HttpException::bad('Закрыть заявку можно только при наличии фото «после»: загрузите фотографию выполненных работ');
            }
            self::transition($id, $to, $user['id'], $comment);
            if ($to === 'closed' && (int)$r['service_paid']) {
                Db::insert('charges', [
                    'request_id' => $id, 'service_id' => (int)$r['service_id'], 'amount' => $r['service_price'],
                    'basis' => $r['service_basis'] ?: 'Заявка собственника, не входящая в перечень обязательных работ',
                    'status' => 'accrued', 'created_by' => $user['id'], 'created_at' => now(),
                ]);
            }
            $num = $r['request_number'];
            $name = self::statusName($to);
            if ($to === 'closed') {
                NotificationService::client((int)$r['client_id'], 'request_closed', "ПосадДом: заявка $num выполнена и закрыта.", $id);
            }
            if ($r['assignee_id'] !== null && (int)$r['assignee_id'] !== (int)($user['employee_id'] ?? 0)) {
                NotificationService::employee((int)$r['assignee_id'], 'status_changed', "Статус заявки $num изменён: «{$name}»", $id);
            }
            if ($role === 'employee' && $to === 'done') {
                NotificationService::dispatchers('status_changed', "Заявка $num выполнена исполнителем {$user['short_name']}: проверьте и закройте", $id);
            }
            Log::audit('request.status', 'request', $id, $from, ['status' => $to, 'comment' => $comment], $user['id']);
            return self::buildCard(self::fetch($id), $user);
        });
    }

    private static function statusName(string $code): string
    {
        return (string)Db::val('SELECT name FROM request_statuses WHERE code = ?', [$code]);
    }

    // ------------------------------------------------------------------ назначение

    public static function candidates(int $id, array $user): array
    {
        if (!in_array($user['role'], self::STAFF, true)) {
            throw HttpException::forbidden();
        }
        $r = self::fetch($id);
        $cur = $r['assignee_id'] !== null ? (int)$r['assignee_id'] : null;
        $list = AssignmentService::candidates(['category_id' => (int)Db::val('SELECT category_id FROM requests WHERE id = ?', [$id]),
            'service_id' => (int)$r['service_id'], 'house_id' => (int)$r['house_id']], null, $cur);
        foreach ($list as &$c) {
            $c['is_current'] = $c['id'] === $cur;
        }
        return $list;
    }

    public static function assign(int $id, ?int $employeeId, ?string $reason, array $user): array
    {
        if (!in_array($user['role'], self::STAFF, true)) {
            throw HttpException::forbidden();
        }
        $r = self::fetch($id);
        if (!in_array($r['status_code'], ['new', 'accepted', 'assigned', 'in_progress'], true)) {
            throw HttpException::conflict('Для заявки в этом статусе назначение невозможно');
        }
        $cur = $r['assignee_id'] !== null ? (int)$r['assignee_id'] : null;
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        if ($employeeId === null) {
            $c = AssignmentService::pick(['category_id' => (int)Db::val('SELECT category_id FROM requests WHERE id = ?', [$id]),
                'service_id' => (int)$r['service_id'], 'house_id' => (int)$r['house_id']], null, $cur);
            if (!$c) {
                throw HttpException::conflict('Подходящих исполнителей нет: назначьте исполнителя вручную');
            }
            AssignmentService::assign($id, $c['id'], $user['id'], 'auto', $reason, $c['off_schedule']);
        } else {
            if ($employeeId === $cur) {
                throw HttpException::bad('Этот исполнитель уже назначен на заявку');
            }
            AssignmentService::assign($id, $employeeId, $user['id'], 'manual', $reason);
        }
        return self::buildCard(self::fetch($id), $user);
    }

    // ------------------------------------------------------------------ комментарии

    public static function addComment(int $id, string $text, bool $additional, array $user): array
    {
        $r = self::fetch($id);
        self::assertAccess($r, $user);
        $text = trim($text);
        if ($text === '') {
            throw HttpException::bad('Введите текст комментария', ['text' => 'Обязательное поле']);
        }
        if (mb_strlen($text) > 2000) {
            throw HttpException::bad('Комментарий не более 2000 символов', ['text' => 'Слишком длинный текст']);
        }
        if ($r['status_code'] === 'closed' && !$additional) {
            throw HttpException::conflict('К закрытой заявке можно добавить только комментарий о дополнительном обращении');
        }
        $cid = Db::insert('request_comments', ['request_id' => $id, 'author_id' => $user['id'], 'text' => $text,
            'is_additional_contact' => $additional ? 1 : 0, 'created_at' => now()]);
        Log::audit('request.comment', 'request', $id, null, ['comment_id' => $cid, 'additional' => $additional], $user['id']);
        return ['id' => $cid, 'author_name' => $user['short_name'], 'text' => $text, 'is_additional_contact' => $additional, 'created_at' => now()];
    }
}
