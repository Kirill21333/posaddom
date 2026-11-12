<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Part2Util as U;

/** Справочник услуг и категорий услуг (ТЗ 7.6, п. 10). */
final class ServiceCatalogController
{
    public const PAID_BASIS = 'Заявка собственника, не входящая в перечень обязательных работ';
    public const FREE_DOC = 'Минимальный перечень услуг и работ по содержанию общего имущества (ПП РФ № 290)';

    /** Подписи полей для журнала изменений. */
    public const FIELD_LABELS = [
        'code' => 'Код', 'category_id' => 'Категория', 'name' => 'Наименование', 'description' => 'Описание',
        'unit' => 'Единица измерения', 'norm_hours' => 'Нормативный срок, ч', 'is_paid' => 'Платность',
        'price' => 'Стоимость, руб.', 'basis' => 'Основание', 'norm_document' => 'Нормативный документ',
        'is_archived' => 'Архив', 'created' => 'Создание услуги',
    ];

    private const SELECT = "SELECT s.id, s.code, s.category_id, sc.name AS category_name, sc.parent_id AS category_parent_id,
            s.name, s.description, s.unit, s.norm_hours, s.is_paid, s.price, s.basis, s.norm_document,
            s.is_archived, s.created_at, s.updated_at,
            (SELECT COUNT(*) FROM requests r WHERE r.service_id = s.id) AS requests_count
        FROM services s JOIN service_categories sc ON sc.id = s.category_id";

    // ------------------------------------------------------------------ категории услуг

    public static function categories(Request $r): array
    {
        $rows = Db::all("SELECT c.id, c.parent_id, c.name, p.name AS parent_name,
                (SELECT COUNT(*) FROM services s WHERE s.category_id = c.id) AS services_count
            FROM service_categories c LEFT JOIN service_categories p ON p.id = c.parent_id ORDER BY COALESCE(c.parent_id, c.id), c.parent_id IS NOT NULL, c.name");
        foreach ($rows as &$c) {
            $c['id'] = (int)$c['id'];
            $c['parent_id'] = $c['parent_id'] === null ? null : (int)$c['parent_id'];
            $c['services_count'] = (int)$c['services_count'];
        }
        return ['items' => $rows];
    }

    public static function saveCategory(Request $r, array $p = []): array
    {
        $id = isset($p['id']) ? (int)$p['id'] : 0;
        $name = (string)$r->str('name', '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw HttpException::bad('Название категории: обязательное поле, не более 100 символов');
        }
        $parent = $r->input('parent_id');
        $parent = ($parent === null || $parent === '') ? null : (int)$parent;
        if ($parent !== null) {
            if (!Db::val('SELECT id FROM service_categories WHERE id = ?', [$parent])) {
                throw HttpException::bad('Родительская категория не найдена');
            }
            if ($id && $parent === $id) {
                throw HttpException::bad('Категория не может быть родителем самой себе');
            }
            // защита от цикла: родитель не должен быть потомком редактируемой категории
            $cur = $parent;
            $guard = 0;
            while ($id && $cur !== null && $guard++ < 20) {
                if ($cur === $id) {
                    throw HttpException::bad('Нельзя сделать категорию вложенной в собственную подкатегорию');
                }
                $cur = Db::val('SELECT parent_id FROM service_categories WHERE id = ?', [$cur]);
                $cur = $cur === null ? null : (int)$cur;
            }
        }
        if ($id) {
            $old = Db::one('SELECT * FROM service_categories WHERE id = ?', [$id]);
            if (!$old) {
                throw HttpException::notFound('Категория услуг не найдена');
            }
            Db::update('service_categories', ['name' => $name, 'parent_id' => $parent], 'id = ?', [$id]);
            U::audit('service_category.update', 'service_category', $id, ['name' => $old['name'], 'parent_id' => $old['parent_id']], ['name' => $name, 'parent_id' => $parent]);
        } else {
            $id = Db::insert('service_categories', ['name' => $name, 'parent_id' => $parent]);
            U::audit('service_category.create', 'service_category', $id, null, ['name' => $name, 'parent_id' => $parent]);
        }
        return Db::one('SELECT id, parent_id, name FROM service_categories WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------ услуги

    public static function list(Request $r): array
    {
        $where = [];
        $params = [];
        $q = $r->q('q');
        if ($q !== null) {
            $where[] = '(s.code LIKE ? OR s.name LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            array_push($params, $like, $like);
        }
        $cat = $r->qint('category_id');
        if ($cat) {
            $where[] = '(s.category_id = ? OR sc.parent_id = ?)';
            array_push($params, $cat, $cat);
        }
        $paid = $r->q('paid');
        if ($paid === '0' || $paid === '1') {
            $where[] = 's.is_paid = ?';
            $params[] = (int)$paid;
        }
        // archived: 0 (по умолчанию) - действующие, 1 - только архивные, all - все
        $arch = $r->q('archived', '0');
        if ($arch === '0') {
            $where[] = 's.is_archived = 0';
        } elseif ($arch === '1') {
            $where[] = 's.is_archived = 1';
        }
        $sql = self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.code';
        $rows = array_map([self::class, 'shape'], Db::all($sql, $params));
        return ['items' => $rows, 'total' => count($rows)];
    }

    public static function get(Request $r, array $p): array
    {
        return self::load((int)$p['id']);
    }

    public static function create(Request $r): array
    {
        U::requireRoles(['admin']);
        $d = self::validate($r, null);
        $me = U::me();
        $id = Db::tx(function () use ($d, $me) {
            $d['created_at'] = now();
            $d['updated_at'] = null;
            $id = Db::insert('services', $d);
            Db::insert('service_changes', [
                'service_id' => $id, 'changed_by' => $me['id'], 'changed_at' => now(),
                'field_name' => 'created', 'old_value' => null, 'new_value' => mb_substr($d['code'] . ' ' . $d['name'], 0, 255),
            ]);
            return $id;
        });
        U::audit('service.create', 'service', $id, null, ['code' => $d['code'], 'name' => $d['name'], 'is_paid' => $d['is_paid'], 'price' => $d['price']]);
        return self::load($id);
    }

    public static function update(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $id = (int)$p['id'];
        $old = Db::one('SELECT * FROM services WHERE id = ?', [$id]);
        if (!$old) {
            throw HttpException::notFound('Услуга не найдена');
        }
        $new = self::validate($r, $old);
        $me = U::me();
        $diff = [];
        foreach ($new as $k => $v) {
            if (self::norm($k, $old[$k] ?? null) !== self::norm($k, $v)) {
                $diff[$k] = [$old[$k] ?? null, $v];
            }
        }
        if ($diff) {
            Db::tx(function () use ($id, $new, $diff, $me) {
                $new['updated_at'] = now();
                Db::update('services', $new, 'id = ?', [$id]);
                foreach ($diff as $field => [$o, $n]) {
                    self::logChange($id, $me['id'], $field, $o, $n);
                }
            });
            U::audit('service.update', 'service', $id, array_map(fn($x) => $x[0], $diff), array_map(fn($x) => $x[1], $diff));
        }
        return self::load($id);
    }

    public static function archive(Request $r, array $p): array
    {
        return self::setArchived((int)$p['id'], 1);
    }

    public static function restore(Request $r, array $p): array
    {
        return self::setArchived((int)$p['id'], 0);
    }

    public static function delete(Request $r, array $p): array
    {
        U::requireRoles(['admin']);
        $id = (int)$p['id'];
        $s = Db::one('SELECT id, code, name FROM services WHERE id = ?', [$id]);
        if (!$s) {
            throw HttpException::notFound('Услуга не найдена');
        }
        $cnt = (int)Db::val('SELECT COUNT(*) FROM requests WHERE service_id = ?', [$id]);
        if ($cnt > 0) {
            throw HttpException::conflict("Услугу нельзя удалить: по ней есть заявки ($cnt). Отправьте её в архив.")
                ->with(['requests_count' => $cnt], 'has_requests');
        }
        Db::tx(function () use ($id) {
            Db::exec('DELETE FROM employee_services WHERE service_id = ?', [$id]);
            Db::exec('DELETE FROM service_changes WHERE service_id = ?', [$id]);
            Db::exec('DELETE FROM services WHERE id = ?', [$id]);
        });
        U::audit('service.delete', 'service', $id, ['code' => $s['code'], 'name' => $s['name']], null);
        return ['deleted' => true, 'id' => $id];
    }

    public static function changes(Request $r, array $p): array
    {
        $id = (int)$p['id'];
        if (!Db::val('SELECT id FROM services WHERE id = ?', [$id])) {
            throw HttpException::notFound('Услуга не найдена');
        }
        $rows = Db::all("SELECT c.id, c.changed_at, c.field_name, c.old_value, c.new_value, c.changed_by AS user_id,
                u.login, e.last_name, e.first_name, e.middle_name
            FROM service_changes c
            JOIN users u ON u.id = c.changed_by
            LEFT JOIN employees e ON e.id = u.employee_id
            WHERE c.service_id = ? ORDER BY c.changed_at DESC, c.id DESC", [$id]);
        $items = [];
        foreach ($rows as $c) {
            $items[] = [
                'id' => (int)$c['id'],
                'changed_at' => $c['changed_at'],
                'field' => $c['field_name'],
                'field_label' => self::FIELD_LABELS[$c['field_name']] ?? $c['field_name'],
                'old_value' => $c['old_value'],
                'new_value' => $c['new_value'],
                'user_id' => (int)$c['user_id'],
                'user_name' => $c['last_name'] !== null ? U::shortName($c) : $c['login'],
            ];
        }
        return ['items' => $items, 'total' => count($items)];
    }

    // ------------------------------------------------------------------ внутреннее

    private static function setArchived(int $id, int $flag): array
    {
        U::requireRoles(['admin']);
        $old = Db::one('SELECT id, is_archived FROM services WHERE id = ?', [$id]);
        if (!$old) {
            throw HttpException::notFound('Услуга не найдена');
        }
        if ((int)$old['is_archived'] !== $flag) {
            $me = U::me();
            Db::tx(function () use ($id, $flag, $old, $me) {
                Db::update('services', ['is_archived' => $flag, 'updated_at' => now()], 'id = ?', [$id]);
                self::logChange($id, $me['id'], 'is_archived', $old['is_archived'], $flag);
            });
            U::audit($flag ? 'service.archive' : 'service.restore', 'service', $id, ['is_archived' => (int)$old['is_archived']], ['is_archived' => $flag]);
        }
        return self::load($id);
    }

    private static function logChange(int $serviceId, int $userId, string $field, $old, $new): void
    {
        $fmt = static function ($v): ?string {
            if ($v === null) {
                return null;
            }
            return mb_substr((string)$v, 0, 255);
        };
        if ($field === 'category_id') {
            $names = static fn($x) => $x === null ? null : (string)Db::val('SELECT name FROM service_categories WHERE id = ?', [(int)$x]);
            $old = $names($old);
            $new = $names($new);
        } elseif ($field === 'is_paid') {
            $old = $old ? 'платная' : 'бесплатная';
            $new = $new ? 'платная' : 'бесплатная';
        } elseif ($field === 'is_archived') {
            $old = $old ? 'в архиве' : 'действует';
            $new = $new ? 'в архиве' : 'действует';
        }
        Db::insert('service_changes', [
            'service_id' => $serviceId, 'changed_by' => $userId, 'changed_at' => now(),
            'field_name' => $field, 'old_value' => $fmt($old), 'new_value' => $fmt($new),
        ]);
    }

    private static function norm(string $k, $v): string
    {
        if ($v === null) {
            return "\0null";
        }
        if (in_array($k, ['norm_hours', 'price', 'is_paid'], true) && is_numeric($v)) {
            return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
        }
        return (string)$v;
    }

    /** Проверка и нормализация входных данных; $old - существующая запись при правке. */
    private static function validate(Request $r, ?array $old): array
    {
        $in = static function (string $k, $default = null) use ($r, $old) {
            if (array_key_exists($k, $r->body)) {
                return $r->body[$k];
            }
            return $old[$k] ?? $default;
        };
        $errors = [];
        $code = trim((string)$in('code', ''));
        if ($code === '' || mb_strlen($code) > 20) {
            $errors['code'] = 'Код услуги: обязательное поле, не более 20 символов';
        }
        $name = trim((string)$in('name', ''));
        if ($name === '' || mb_strlen($name) > 200) {
            $errors['name'] = 'Наименование: обязательное поле, не более 200 символов';
        }
        $unit = trim((string)$in('unit', ''));
        if ($unit === '' || mb_strlen($unit) > 20) {
            $errors['unit'] = 'Единица измерения: обязательное поле, не более 20 символов';
        }
        $catId = (int)$in('category_id', 0);
        if (!$catId || !Db::val('SELECT id FROM service_categories WHERE id = ?', [$catId])) {
            $errors['category_id'] = 'Выберите категорию услуги';
        }
        $nh = $in('norm_hours');
        if (!is_numeric($nh) || (float)$nh <= 0 || (float)$nh > 9999.9) {
            $errors['norm_hours'] = 'Нормативный срок: положительное число часов';
        }
        $paid = U::flag($in('is_paid', 0));
        $price = $in('price');
        $price = ($price === null || $price === '') ? null : $price;
        if ($paid) {
            if ($price === null || !is_numeric($price) || (float)$price <= 0 || (float)$price > 99999999) {
                $errors['price'] = 'Для платной услуги укажите стоимость (положительное число)';
            }
        } else {
            $price = null;
        }
        $basis = trim((string)$in('basis', ''));
        $doc = trim((string)$in('norm_document', ''));
        if ($paid) {
            $basis = $basis !== '' ? $basis : self::PAID_BASIS;
            $doc = '';
        } else {
            $doc = $doc !== '' ? $doc : self::FREE_DOC;
            $basis = '';
        }
        if (mb_strlen($basis) > 255 || mb_strlen($doc) > 255) {
            $errors['basis'] = 'Основание и нормативный документ: не более 255 символов';
        }
        $desc = $in('description');
        $desc = ($desc === null || trim((string)$desc) === '') ? null : trim((string)$desc);
        if (!$errors && Db::val('SELECT id FROM services WHERE code = ? AND id <> ?', [$code, $old['id'] ?? 0])) {
            $errors['code'] = 'Услуга с кодом «' . $code . '» уже есть в справочнике';
        }
        if ($errors) {
            $e = HttpException::bad(reset($errors), $errors);
            if (isset($errors['code']) && str_contains($errors['code'], 'уже есть')) {
                throw HttpException::conflict($errors['code'])->with(['fields' => $errors], 'duplicate_code');
            }
            throw $e;
        }
        return [
            'code' => $code, 'category_id' => $catId, 'name' => $name, 'description' => $desc, 'unit' => $unit,
            'norm_hours' => round((float)$nh, 1), 'is_paid' => $paid,
            'price' => $price === null ? null : round((float)$price, 2),
            'basis' => $basis === '' ? null : $basis, 'norm_document' => $doc === '' ? null : $doc,
        ];
    }

    private static function load(int $id): array
    {
        $row = Db::one(self::SELECT . ' WHERE s.id = ?', [$id]);
        if (!$row) {
            throw HttpException::notFound('Услуга не найдена');
        }
        return self::shape($row);
    }

    private static function shape(array $s): array
    {
        return [
            'id' => (int)$s['id'],
            'code' => $s['code'],
            'name' => $s['name'],
            'description' => $s['description'],
            'category_id' => (int)$s['category_id'],
            'category_name' => $s['category_name'],
            'category' => ['id' => (int)$s['category_id'], 'name' => $s['category_name']],
            'unit' => $s['unit'],
            'norm_hours' => (float)$s['norm_hours'],
            'is_paid' => (int)$s['is_paid'],
            'price' => $s['price'] === null ? null : (float)$s['price'],
            'basis' => $s['basis'],
            'norm_document' => $s['norm_document'],
            'is_archived' => (int)$s['is_archived'],
            'requests_count' => (int)$s['requests_count'],
            'can_delete' => (int)$s['requests_count'] === 0,
            'created_at' => $s['created_at'],
            'updated_at' => $s['updated_at'],
        ];
    }
}
