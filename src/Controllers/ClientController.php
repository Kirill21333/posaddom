<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Part2Util as U;

/** Клиенты, дома и помещения. */
final class ClientController
{
    private const CLIENT_SELECT = "SELECT c.id, c.last_name, c.first_name, c.middle_name, c.phone, c.email, c.status_id,
            cs.code AS status_code, cs.name AS status_name, c.notify_consent, c.pd_consent_at, c.created_at,
            (SELECT COUNT(*) FROM requests r WHERE r.client_id = c.id) AS requests_count
        FROM clients c JOIN client_statuses cs ON cs.id = c.status_id";

    // ------------------------------------------------------------------ клиенты

    public static function list(Request $r): array
    {
        $where = [];
        $params = [];
        $q = $r->q('q');
        if ($q !== null) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $parts = ["CONCAT_WS(' ', c.last_name, c.first_name, c.middle_name) LIKE ?", 'c.email LIKE ?'];
            $params = [$like, $like];
            $digits = U::digits($q);
            if (strlen($digits) >= 3) {
                $parts[] = U::sqlDigits('c.phone') . ' LIKE ?';
                $params[] = '%' . $digits . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $house = $r->qint('house');
        if ($house) {
            $where[] = 'EXISTS (SELECT 1 FROM client_premises cp JOIN premises p ON p.id = cp.premise_id WHERE cp.client_id = c.id AND p.house_id = ?)';
            $params[] = $house;
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int)Db::val('SELECT COUNT(*) FROM clients c' . $w, $params);
        [$page, $per, $off] = U::paging($r, 50, 200);
        $rows = Db::all(self::CLIENT_SELECT . $w . " ORDER BY c.last_name, c.first_name, c.id LIMIT $per OFFSET $off", $params);
        $prem = self::premisesFor(array_map(fn($x) => (int)$x['id'], $rows));
        $items = [];
        foreach ($rows as $row) {
            $it = self::shape($row);
            $it['premises'] = $prem[(int)$row['id']] ?? [];
            $items[] = $it;
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    public static function lookup(Request $r): array
    {
        $phone = (string)$r->q('phone', '');
        $key = U::phoneKey($phone);
        if (strlen($key) < 10) {
            throw HttpException::bad('Телефон: укажите номер полностью (не менее 10 цифр)');
        }
        $rows = Db::all(self::CLIENT_SELECT . ' WHERE RIGHT(' . U::sqlDigits('c.phone') . ', 10) = ? ORDER BY c.id DESC', [$key]);
        if (!$rows) {
            return ['client' => null, 'clients' => []];
        }
        $ids = array_map(fn($x) => (int)$x['id'], $rows);
        $prem = self::premisesFor($ids);
        $out = [];
        foreach ($rows as $row) {
            $it = self::shape($row);
            $it['premises'] = $prem[(int)$row['id']] ?? [];
            $out[] = $it;
        }
        return ['client' => $out[0], 'clients' => $out];
    }

    public static function get(Request $r, array $p): array
    {
        $id = (int)$p['id'];
        $row = Db::one(self::CLIENT_SELECT . ' WHERE c.id = ?', [$id]);
        if (!$row) {
            throw HttpException::notFound('Клиент не найден');
        }
        $it = self::shape($row);
        $it['premises'] = self::premisesFor([$id])[$id] ?? [];
        $reqs = Db::all("SELECT r.id, r.request_number, r.received_at, r.closed_at, r.completed_at, r.due_at,
                c.code AS category_code, c.name AS category_name, s.code AS service_code, s.name AS service_name,
                st.code AS status_code, st.name AS status_name, h.address, p.number AS premise_number,
                e.id AS emp_id, e.last_name, e.first_name, e.middle_name
            FROM requests r
            JOIN request_categories c ON c.id = r.category_id
            JOIN services s ON s.id = r.service_id
            JOIN request_statuses st ON st.id = r.status_id
            JOIN houses h ON h.id = r.house_id
            LEFT JOIN premises p ON p.id = r.premise_id
            LEFT JOIN employees e ON e.id = r.assignee_id
            WHERE r.client_id = ? ORDER BY r.received_at DESC, r.id DESC", [$id]);
        $it['requests'] = array_map(fn($x) => [
            'id' => (int)$x['id'], 'number' => $x['request_number'], 'received_at' => $x['received_at'],
            'category' => ['code' => $x['category_code'], 'name' => $x['category_name']],
            'service' => ['code' => $x['service_code'], 'name' => $x['service_name']],
            'house' => $x['address'], 'premise' => $x['premise_number'],
            'assignee' => $x['emp_id'] ? ['id' => (int)$x['emp_id'], 'full_name' => U::fullName($x), 'short_name' => U::shortName($x)] : null,
            'status' => ['code' => $x['status_code'], 'name' => $x['status_name']],
            'due_at' => $x['due_at'], 'closed_at' => $x['closed_at'],
        ], $reqs);
        return $it;
    }

    public static function create(Request $r): array
    {
        U::requireRoles(['dispatcher', 'admin']);
        $d = self::validate($r, null);
        $prem = self::premiseInput($r);
        $confirm = U::flag($r->body['confirm_duplicate'] ?? 0) === 1;

        $dups = [];
        if ($prem) {
            $dups = self::findDuplicates($d['phone'], $prem);
            if ($dups && !$confirm) {
                throw HttpException::conflict('Клиент с таким телефоном и помещением уже есть в справочнике. Подтвердите создание дубля или выберите найденного клиента.')
                    ->with(['duplicate' => self::dupInfo($dups[0], $dups), 'data' => ['duplicate' => self::dupInfo($dups[0], $dups)]], 'duplicate');
            }
        }
        $id = Db::tx(function () use ($d, $prem) {
            $d['created_at'] = now();
            $id = Db::insert('clients', $d);
            if ($prem) {
                $pid = self::resolvePremise($prem);
                Db::exec('INSERT IGNORE INTO client_premises (client_id, premise_id, since_date) VALUES (?, ?, ?)', [$id, $pid, date('Y-m-d')]);
            }
            return $id;
        });
        U::audit('client.create', 'client', $id, null, ['name' => U::fullName($d), 'phone' => $d['phone']]);
        if ($dups) {
            U::audit('client.duplicate_confirmed', 'client', $id, ['duplicate_of' => array_map(fn($x) => (int)$x['id'], $dups)], ['phone' => $d['phone'], 'premise' => $prem]);
        }
        return self::get($r, ['id' => $id]);
    }

    public static function update(Request $r, array $p): array
    {
        U::requireRoles(['dispatcher', 'admin']);
        $id = (int)$p['id'];
        $old = Db::one('SELECT * FROM clients WHERE id = ?', [$id]);
        if (!$old) {
            throw HttpException::notFound('Клиент не найден');
        }
        $d = self::validate($r, $old);
        $prem = self::premiseInput($r);
        $diff = [];
        foreach ($d as $k => $v) {
            if ((string)($old[$k] ?? '') !== (string)($v ?? '')) {
                $diff[$k] = [$old[$k], $v];
            }
        }
        Db::tx(function () use ($id, $d, $prem) {
            Db::update('clients', $d, 'id = ?', [$id]);
            if ($prem) {
                $pid = self::resolvePremise($prem);
                Db::exec('INSERT IGNORE INTO client_premises (client_id, premise_id, since_date) VALUES (?, ?, ?)', [$id, $pid, date('Y-m-d')]);
            }
        });
        if ($diff || $prem) {
            U::audit('client.update', 'client', $id, array_map(fn($x) => $x[0], $diff), array_map(fn($x) => $x[1], $diff) + ($prem ? ['premise_added' => $prem] : []));
        }
        return self::get($r, ['id' => $id]);
    }

    // ------------------------------------------------------------------ дома и помещения

    public static function houses(Request $r): array
    {
        $where = [];
        $params = [];
        $q = $r->q('q');
        if ($q !== null) {
            $where[] = 'h.address LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        }
        if ($r->q('active') === '1') {
            $where[] = 'h.is_active = 1';
        }
        $rows = Db::all("SELECT h.id, h.section_id, sec.name AS section_name, h.address, h.floors, h.entrances, h.premises_count, h.year_built, h.is_active,
                (SELECT COUNT(*) FROM premises p WHERE p.house_id = h.id) AS premises_registered,
                (SELECT COUNT(*) FROM requests r WHERE r.house_id = h.id) AS requests_count
            FROM houses h JOIN sections sec ON sec.id = h.section_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY h.address', $params);
        return ['items' => array_map([self::class, 'houseShape'], $rows), 'total' => count($rows)];
    }

    public static function premises(Request $r, array $p): array
    {
        $hid = (int)$p['id'];
        if (!Db::val('SELECT id FROM houses WHERE id = ?', [$hid])) {
            throw HttpException::notFound('Дом не найден');
        }
        $q = $r->q('q');
        $sql = 'SELECT id, house_id, number, type, area FROM premises WHERE house_id = ?';
        $params = [$hid];
        if ($q !== null) {
            $sql .= ' AND number LIKE ?';
            $params[] = $q . '%';
        }
        $rows = Db::all($sql . ' ORDER BY CAST(number AS UNSIGNED), number', $params);
        return ['items' => array_map([self::class, 'premiseShape'], $rows), 'total' => count($rows)];
    }

    public static function createHouse(Request $r): array
    {
        U::requireRoles(['admin']);
        $d = self::validateHouse($r, null);
        $id = Db::insert('houses', $d);
        U::audit('house.create', 'house', $id, null, $d);
        return self::houseById($id);
    }

    public static function updateHouse(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $id = (int)$p['id'];
        $old = Db::one('SELECT * FROM houses WHERE id = ?', [$id]);
        if (!$old) {
            throw HttpException::notFound('Дом не найден');
        }
        $d = self::validateHouse($r, $old);
        $diff = [];
        foreach ($d as $k => $v) {
            if ((string)$old[$k] !== (string)$v) {
                $diff[$k] = [$old[$k], $v];
            }
        }
        Db::update('houses', $d, 'id = ?', [$id]);
        if ($diff) {
            U::audit('house.update', 'house', $id, array_map(fn($x) => $x[0], $diff), array_map(fn($x) => $x[1], $diff));
        }
        return self::houseById($id);
    }

    public static function createPremise(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $hid = (int)$p['id'];
        if (!Db::val('SELECT id FROM houses WHERE id = ?', [$hid])) {
            throw HttpException::notFound('Дом не найден');
        }
        $d = self::validatePremise($r, $hid, null);
        $id = Db::insert('premises', $d);
        U::audit('premise.create', 'premise', $id, null, $d);
        return self::premiseShape(Db::one('SELECT id, house_id, number, type, area FROM premises WHERE id = ?', [$id]));
    }

    public static function updatePremise(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $hid = (int)$p['id'];
        $pid = (int)$p['pid'];
        $old = Db::one('SELECT * FROM premises WHERE id = ? AND house_id = ?', [$pid, $hid]);
        if (!$old) {
            throw HttpException::notFound('Помещение не найдено');
        }
        $d = self::validatePremise($r, $hid, $old);
        Db::update('premises', $d, 'id = ?', [$pid]);
        U::audit('premise.update', 'premise', $pid, ['number' => $old['number'], 'type' => $old['type'], 'area' => $old['area']], $d);
        return self::premiseShape(Db::one('SELECT id, house_id, number, type, area FROM premises WHERE id = ?', [$pid]));
    }

    // ------------------------------------------------------------------ внутреннее: клиенты

    private static function shape(array $c): array
    {
        return [
            'id' => (int)$c['id'],
            'last_name' => $c['last_name'], 'first_name' => $c['first_name'], 'middle_name' => $c['middle_name'],
            'full_name' => U::fullName($c), 'phone' => $c['phone'], 'email' => $c['email'],
            'status' => ['id' => (int)$c['status_id'], 'code' => $c['status_code'], 'name' => $c['status_name']],
            'notify_consent' => (int)$c['notify_consent'], 'pd_consent_at' => $c['pd_consent_at'], 'created_at' => $c['created_at'],
            'requests_count' => (int)$c['requests_count'],
        ];
    }

    /** @return array<int,array> client_id => [помещения] */
    private static function premisesFor(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $rows = Db::all('SELECT cp.client_id, p.id AS premise_id, p.number, p.type, h.id AS house_id, h.address
            FROM client_premises cp JOIN premises p ON p.id = cp.premise_id JOIN houses h ON h.id = p.house_id
            WHERE cp.client_id IN (' . Db::in($ids) . ') ORDER BY h.address, p.number', array_values($ids));
        $out = [];
        foreach ($rows as $x) {
            $out[(int)$x['client_id']][] = [
                'premise_id' => (int)$x['premise_id'], 'number' => $x['number'], 'type' => $x['type'],
                'house_id' => (int)$x['house_id'], 'address' => $x['address'],
            ];
        }
        return $out;
    }

    private static function validate(Request $r, ?array $old): array
    {
        $in = static fn(string $k, $def = null) => array_key_exists($k, $r->body) ? $r->body[$k] : ($old[$k] ?? $def);
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
        $phone = trim((string)$in('phone', ''));
        if (!preg_match('/^\+?[0-9\s\-\(\)]{10,20}$/', $phone) || strlen(U::digits($phone)) < 10) {
            $errors['phone'] = 'Телефон: укажите номер в формате +7 (900) 000-00-00';
        }
        $email = trim((string)$in('email', ''));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255)) {
            $errors['email'] = 'Некорректный e-mail';
        }
        $statusId = null;
        $code = $r->body['status'] ?? $r->body['status_code'] ?? null;
        if (is_string($code) && $code !== '') {
            $statusId = Db::val('SELECT id FROM client_statuses WHERE code = ?', [$code]);
        } elseif (array_key_exists('status_id', $r->body)) {
            $statusId = Db::val('SELECT id FROM client_statuses WHERE id = ?', [(int)$r->body['status_id']]);
        } elseif ($old) {
            $statusId = $old['status_id'];
        } else {
            $statusId = Db::val("SELECT id FROM client_statuses WHERE code = 'owner'");
        }
        if (!$statusId) {
            $errors['status_id'] = 'Недопустимый статус клиента';
        }
        $pd = $old['pd_consent_at'] ?? null;
        if (array_key_exists('pd_consent', $r->body)) {
            if (!U::flag($r->body['pd_consent'])) {
                $errors['pd_consent'] = 'Необходимо согласие клиента на обработку персональных данных';
            } else {
                $pd = $pd ?: now();
            }
        } elseif (!$old) {
            $pd = now();
        }
        if ($errors) {
            throw HttpException::bad(reset($errors), $errors);
        }
        return [
            'last_name' => $last, 'first_name' => $first, 'middle_name' => $mid === '' ? null : $mid,
            'phone' => $phone, 'email' => $email === '' ? null : $email, 'status_id' => (int)$statusId,
            'notify_consent' => U::flag($in('notify_consent', 0)), 'pd_consent_at' => $pd,
        ];
    }

    /** Помещение из тела запроса: {premise_id} или {premise:{house_id,number,type?}}. */
    private static function premiseInput(Request $r): ?array
    {
        $pr = $r->body['premise'] ?? null;
        if (is_array($pr)) {
            if (!empty($pr['id']) || !empty($pr['premise_id'])) {
                $row = Db::one('SELECT id, house_id, number FROM premises WHERE id = ?', [(int)($pr['id'] ?? $pr['premise_id'])]);
                if (!$row) {
                    throw HttpException::bad('Помещение не найдено');
                }
                return ['premise_id' => (int)$row['id'], 'house_id' => (int)$row['house_id'], 'number' => $row['number']];
            }
            $hid = (int)($pr['house_id'] ?? 0);
            $num = trim((string)($pr['number'] ?? ''));
            if ($hid === 0 && $num === '') {
                return null;
            }
            if (!$hid || !Db::val('SELECT id FROM houses WHERE id = ?', [$hid])) {
                throw HttpException::bad('Помещение: выберите дом');
            }
            if ($num === '' || mb_strlen($num) > 10) {
                throw HttpException::bad('Помещение: укажите номер (не более 10 символов)');
            }
            $type = (string)($pr['type'] ?? 'residential');
            if (!in_array($type, ['residential', 'non_residential'], true)) {
                throw HttpException::bad('Тип помещения: residential или non_residential');
            }
            return ['house_id' => $hid, 'number' => $num, 'type' => $type];
        }
        $pid = (int)($r->body['premise_id'] ?? 0);
        if ($pid) {
            $row = Db::one('SELECT id, house_id, number FROM premises WHERE id = ?', [$pid]);
            if (!$row) {
                throw HttpException::bad('Помещение не найдено');
            }
            return ['premise_id' => $pid, 'house_id' => (int)$row['house_id'], 'number' => $row['number']];
        }
        return null;
    }

    /** Находит или создаёт помещение, возвращает его id. */
    private static function resolvePremise(array $prem): int
    {
        if (!empty($prem['premise_id'])) {
            return (int)$prem['premise_id'];
        }
        $id = Db::val('SELECT id FROM premises WHERE house_id = ? AND number = ?', [$prem['house_id'], $prem['number']]);
        if ($id) {
            return (int)$id;
        }
        return Db::insert('premises', ['house_id' => $prem['house_id'], 'number' => $prem['number'], 'type' => $prem['type'] ?? 'residential']);
    }

    /** Клиенты с тем же телефоном, привязанные к тому же помещению. */
    private static function findDuplicates(string $phone, array $prem): array
    {
        $key = U::phoneKey($phone);
        $rows = Db::all('SELECT DISTINCT c.id FROM clients c
                JOIN client_premises cp ON cp.client_id = c.id JOIN premises p ON p.id = cp.premise_id
                WHERE RIGHT(' . U::sqlDigits('c.phone') . ', 10) = ? AND p.house_id = ? AND p.number = ? ORDER BY c.id', [$key, $prem['house_id'], $prem['number']]);
        $out = [];
        foreach ($rows as $x) {
            $out[] = Db::one(self::CLIENT_SELECT . ' WHERE c.id = ?', [(int)$x['id']]);
        }
        return $out;
    }

    private static function dupInfo(array $first, array $all): array
    {
        $s = self::shape($first);
        $s['premises'] = self::premisesFor([(int)$first['id']])[(int)$first['id']] ?? [];
        return ['client' => $s, 'count' => count($all), 'ids' => array_map(fn($x) => (int)$x['id'], $all)] + $s;
    }

    // ------------------------------------------------------------------ внутреннее: дома

    private static function houseById(int $id): array
    {
        $row = Db::one("SELECT h.id, h.section_id, sec.name AS section_name, h.address, h.floors, h.entrances, h.premises_count, h.year_built, h.is_active,
                (SELECT COUNT(*) FROM premises p WHERE p.house_id = h.id) AS premises_registered,
                (SELECT COUNT(*) FROM requests r WHERE r.house_id = h.id) AS requests_count
            FROM houses h JOIN sections sec ON sec.id = h.section_id WHERE h.id = ?", [$id]);
        return self::houseShape($row);
    }

    private static function houseShape(array $h): array
    {
        return [
            'id' => (int)$h['id'], 'address' => $h['address'],
            'section_id' => (int)$h['section_id'], 'section' => $h['section_name'],
            'floors' => (int)$h['floors'], 'entrances' => (int)$h['entrances'], 'premises_count' => (int)$h['premises_count'],
            'year_built' => $h['year_built'] === null ? null : (int)$h['year_built'], 'is_active' => (int)$h['is_active'],
            'premises_registered' => (int)$h['premises_registered'], 'requests_count' => (int)$h['requests_count'],
        ];
    }

    private static function premiseShape(array $p): array
    {
        return ['id' => (int)$p['id'], 'house_id' => (int)$p['house_id'], 'number' => $p['number'], 'type' => $p['type'], 'area' => $p['area'] === null ? null : (float)$p['area']];
    }

    private static function validateHouse(Request $r, ?array $old): array
    {
        $in = static fn(string $k, $def = null) => array_key_exists($k, $r->body) ? $r->body[$k] : ($old[$k] ?? $def);
        $errors = [];
        $addr = trim((string)$in('address', ''));
        if ($addr === '' || mb_strlen($addr) > 255) {
            $errors['address'] = 'Адрес: обязательное поле, не более 255 символов';
        }
        $sec = (int)$in('section_id', 0);
        if (!$sec || !Db::val('SELECT id FROM sections WHERE id = ?', [$sec])) {
            $errors['section_id'] = 'Выберите участок';
        }
        $num = static function (string $k, int $min, int $max, string $label) use ($in, &$errors): int {
            $v = $in($k, 0);
            if (!is_numeric($v) || (int)$v < $min || (int)$v > $max) {
                $errors[$k] = "$label: число от $min до $max";
                return 0;
            }
            return (int)$v;
        };
        $floors = $num('floors', 1, 255, 'Этажность');
        $ent = $num('entrances', 1, 255, 'Число подъездов');
        $pc = $num('premises_count', 1, 65535, 'Число помещений');
        $yb = $in('year_built');
        $yb = ($yb === null || $yb === '') ? null : (int)$yb;
        if ($yb !== null && ($yb < 1800 || $yb > (int)date('Y'))) {
            $errors['year_built'] = 'Год постройки: некорректное значение';
        }
        if (!$errors && Db::val('SELECT id FROM houses WHERE address = ? AND id <> ?', [$addr, $old['id'] ?? 0])) {
            throw HttpException::conflict('Дом с таким адресом уже есть в справочнике');
        }
        if ($errors) {
            throw HttpException::bad(reset($errors), $errors);
        }
        return [
            'section_id' => $sec, 'address' => $addr, 'floors' => $floors, 'entrances' => $ent, 'premises_count' => $pc,
            'year_built' => $yb, 'is_active' => U::flag($in('is_active', 1)),
        ];
    }

    private static function validatePremise(Request $r, int $hid, ?array $old): array
    {
        $in = static fn(string $k, $def = null) => array_key_exists($k, $r->body) ? $r->body[$k] : ($old[$k] ?? $def);
        $num = trim((string)$in('number', ''));
        if ($num === '' || mb_strlen($num) > 10) {
            throw HttpException::bad('Номер помещения: обязательное поле, не более 10 символов');
        }
        $type = (string)$in('type', 'residential');
        if (!in_array($type, ['residential', 'non_residential'], true)) {
            throw HttpException::bad('Тип помещения: residential или non_residential');
        }
        $area = $in('area');
        $area = ($area === null || $area === '') ? null : $area;
        if ($area !== null && (!is_numeric($area) || (float)$area <= 0 || (float)$area > 99999)) {
            throw HttpException::bad('Площадь: положительное число');
        }
        if (Db::val('SELECT id FROM premises WHERE house_id = ? AND number = ? AND id <> ?', [$hid, $num, $old['id'] ?? 0])) {
            throw HttpException::conflict('Помещение с таким номером уже есть в этом доме');
        }
        return ['house_id' => $hid, 'number' => $num, 'type' => $type, 'area' => $area === null ? null : round((float)$area, 2)];
    }
}
