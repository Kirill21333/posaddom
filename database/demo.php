<?php
// Демонстрационные данные ИС «ПосадДом». Подключается из install.php при ключе --demo.
// Все моменты времени считаются от текущего момента, чтобы просрочки и таймеры выглядели правдоподобно в любой день.
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Db;
use App\Services\DeadlineService;

const DEMO_PASSWORD = 'Demo12345';

$NOW = time();
/** Момент времени: сейчас + $min минут (отрицательное - в прошлом). */
function at(int $min): string
{
    global $NOW;
    return date('Y-m-d H:i:s', $NOW + $min * 60);
}

function placeholder(string $path, string $label, int $w = 320, int $h = 240): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 0xD9, 0xD9, 0xD9));
        imagerectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 0x99, 0x99, 0x99));
        imagestring($im, 5, (int)(($w - strlen($label) * 9) / 2), (int)($h / 2 - 8), $label, imagecolorallocate($im, 0x55, 0x55, 0x55));
        if (substr($path, -4) === '.jpg') {
            imagejpeg($im, $path, 80);
        } else {
            imagepng($im, $path);
        }
        imagedestroy($im);
        return;
    }
    // GD не подключён: PNG собирается вручную (серый квадрат с рамкой)
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < $w; $x++) {
            $edge = $x === 0 || $y === 0 || $x === $w - 1 || $y === $h - 1;
            $raw .= $edge ? "\x99\x99\x99" : "\xD9\xD9\xD9";
        }
    }
    $chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw, 6)) . $chunk('IEND', '');
    file_put_contents(substr($path, -4) === '.jpg' ? substr($path, 0, -4) . '.png' : $path, $png);
}

$pwHash = Auth::hashPassword(DEMO_PASSWORD);
$pos = array_column(Db::all('SELECT id, name FROM positions'), 'id', 'name');
$est = array_column(Db::all('SELECT id, code FROM employee_statuses'), 'id', 'code');
$sty = array_column(Db::all('SELECT id, code FROM schedule_types'), 'id', 'code');
$role = array_column(Db::all('SELECT id, code FROM roles'), 'id', 'code');
$cat = [];
foreach (Db::all('SELECT * FROM request_categories') as $c) {
    $cat[$c['code']] = $c;
}
$stat = array_column(Db::all('SELECT id, code FROM request_statuses'), 'id', 'code');
$chan = array_column(Db::all('SELECT id, code FROM request_channels'), 'id', 'code');

// ------------------------------------------------------------ подрядчик, сотрудники, пользователи
$contractor = Db::insert('contractors', ['name' => 'ООО «АкваСервис»', 'inn' => '5042123456', 'phone' => '+7 (496) 540-11-22', 'is_active' => 1]);

$E = []; // ключ => id сотрудника
$U = []; // ключ => id пользователя
$employees = [
    // ключ, логин, ФИО, должность, статус, график, роль, e-mail-префикс, подрядчик
    ['morozova', 'morozova.ev', ['Морозова', 'Елена', 'Викторовна'], 'Диспетчер', 'active', 'five_day', 'dispatcher'],
    ['vasilyev', 'vasilyev.op', ['Васильев', 'Олег', 'Петрович'], 'Главный инженер', 'active', 'five_day', 'manager'],
    ['gromov', 'gromov.ks', ['Громов', 'Константин', 'Сергеевич'], 'Системный администратор', 'active', 'five_day', 'admin'],
    ['smirnov', 'smirnov.av', ['Смирнов', 'Алексей', 'Викторович'], 'Слесарь-сантехник', 'active', 'five_day', 'employee'],
    ['gusev', 'gusev.di', ['Гусев', 'Дмитрий', 'Игоревич'], 'Электромонтёр', 'active', 'shift', 'employee'],
    ['kuzmin', 'kuzmin.pn', ['Кузьмин', 'Павел', 'Николаевич'], 'Плотник', 'active', 'five_day', 'employee'],
    ['belova', 'belova.na', ['Белова', 'Наталья', 'Андреевна'], 'Мастер участка', 'active', 'five_day', 'employee'],
    ['fedorov', 'fedorov.se', ['Фёдоров', 'Сергей', 'Егорович'], 'Слесарь-сантехник', 'active', 'round_clock', 'employee'],
    ['ivanova', 'ivanova.ms', ['Иванова', 'Марина', 'Сергеевна'], 'Уборщик', 'vacation', 'five_day', 'employee'],
    ['orlov', 'orlov.va', ['Орлов', 'Виктор', 'Алексеевич'], 'Электромонтёр', 'sick', 'five_day', 'employee'],
    ['ivanov', 'ivanov.rk', ['Иванов', 'Роман', 'Константинович'], 'Слесарь-сантехник', 'active', 'shift', 'employee'],
];
$n = 0;
foreach ($employees as [$key, $login, $fio, $position, $status, $sched, $r]) {
    $n++;
    $E[$key] = Db::insert('employees', [
        'last_name' => $fio[0], 'first_name' => $fio[1], 'middle_name' => $fio[2],
        'position_id' => $pos[$position], 'status_id' => $est[$status], 'schedule_type_id' => $sty[$sched],
        'contractor_id' => $key === 'ivanov' ? $contractor : null,
        'phone' => sprintf('+7 (496) 500-%02d-%02d', 10 + $n, 30 + $n), 'email' => str_replace('.', '_', $login) . '@posaddom.example',
        'hire_date' => date('Y-m-d', strtotime('-' . (200 + $n * 150) . ' days')), 'pd_consent_at' => at(-60 * 24 * 90), 'created_at' => at(-60 * 24 * 90),
    ]);
    $U[$key] = Db::insert('users', [
        'login' => $login, 'password_hash' => $pwHash, 'role_id' => $role[$r], 'employee_id' => $E[$key],
        'email' => str_replace('.', '_', $login) . '@posaddom.example', 'is_active' => 1, 'last_login_at' => at(-60 * 20), 'created_at' => at(-60 * 24 * 90),
    ]);
}
Db::exec('UPDATE sections SET master_id = ? WHERE name = ?', [$E['belova'], 'Участок №2']);

// графики работы: weekday 1 = понедельник ... 7 = воскресенье
function schedule(int $emp, array $days, string $shift, string $from, string $to, ?string $bs = null, ?string $be = null): void
{
    foreach ($days as $d) {
        Db::insert('work_schedules', ['employee_id' => $emp, 'weekday' => $d, 'shift_type' => $shift,
            'start_time' => $from, 'end_time' => $to, 'break_start' => $bs, 'break_end' => $be]);
    }
}
foreach (['morozova', 'vasilyev', 'gromov', 'smirnov', 'kuzmin', 'belova', 'ivanova', 'orlov'] as $k) {
    schedule($E[$k], [1, 2, 3, 4, 5], 'day', '09:00:00', '18:00:00', '13:00:00', '14:00:00');
}
schedule($E['gusev'], [1, 2, 5, 6], 'day', '08:00:00', '20:00:00');   // сменный график 2 через 2
schedule($E['gusev'], [3, 4], 'night', '20:00:00', '08:00:00');
schedule($E['fedorov'], [1, 2, 3, 4, 5, 6, 7], 'full', '00:00:00', '23:59:59');  // АДС, круглосуточно
schedule($E['ivanov'], [1, 2, 3, 4, 5], 'night', '22:00:00', '06:00:00');  // ночные смены: днём «вне графика»
$d0 = fn(int $k) => date('Y-m-d', strtotime(sprintf('%+d days', $k)));
for ($k = -3; $k <= 5; $k++) {
    Db::insert('schedule_exceptions', ['employee_id' => $E['ivanova'], 'exception_date' => $d0($k), 'is_day_off' => 1, 'reason' => 'Ежегодный отпуск']);
    Db::insert('schedule_exceptions', ['employee_id' => $E['orlov'], 'exception_date' => $d0($k), 'is_day_off' => 1, 'reason' => 'Больничный лист']);
}
Db::insert('schedule_exceptions', ['employee_id' => $E['kuzmin'], 'exception_date' => $d0(1), 'start_time' => '09:00:00', 'end_time' => '13:00:00', 'is_day_off' => 0, 'reason' => 'Приём в поликлинике']);
Db::insert('schedule_exceptions', ['employee_id' => $E['smirnov'], 'exception_date' => $d0(9), 'is_day_off' => 1, 'reason' => 'Отгул']);

// ------------------------------------------------------------ дома и помещения
$houses = [
    // ключ => [адрес, участок, этажей, подъездов, помещений, год]
    1 => ['пр-т Красной Армии, 148', 'Участок №2', 9, 4, 72, 1985],
    2 => ['ул. Вознесенская, 12', 'Участок №1', 5, 3, 60, 1962],
    3 => ['Новоугличское ш., 52', 'Участок №3', 9, 3, 48, 1990],
    4 => ['ул. Дружбы, 7', 'Участок №2', 5, 2, 36, 1974],
    5 => ['ул. Центральная, 21', 'Участок №4', 12, 4, 72, 2004],
    6 => ['ул. Школьная, 4', 'Участок №5', 5, 2, 40, 1968],
    7 => ['ул. Андреевская, 15', 'Участок №1', 4, 2, 36, 1958],
    8 => ['ул. Куликова, 9', 'Участок №3', 5, 3, 45, 1981],
];
$H = [];
$P = []; // [дом][номер] => id помещения
$sec = array_column(Db::all('SELECT id, name FROM sections'), 'id', 'name');
foreach ($houses as $k => [$addr, $section, $floors, $ent, $cnt, $year]) {
    $H[$k] = Db::insert('houses', ['section_id' => $sec[$section], 'address' => $addr, 'floors' => $floors, 'entrances' => $ent,
        'premises_count' => $cnt, 'year_built' => $year, 'is_active' => 1]);
    for ($i = 1; $i <= $cnt; $i++) {
        $P[$k][(string)$i] = Db::insert('premises', ['house_id' => $H[$k], 'number' => (string)$i, 'type' => 'residential', 'area' => 30 + ($i * 7) % 45 + 0.5]);
    }
}
$P[2]['Н1'] = Db::insert('premises', ['house_id' => $H[2], 'number' => 'Н1', 'type' => 'non_residential', 'area' => 64.2]);
$P[5]['Н1'] = Db::insert('premises', ['house_id' => $H[5], 'number' => 'Н1', 'type' => 'non_residential', 'area' => 118.0]);

// ------------------------------------------------------------ клиенты
$cst = array_column(Db::all('SELECT id, code FROM client_statuses'), 'id', 'code');
$C = [];
$clients = [
    'petrova' => ['Петрова', 'Анна', 'Сергеевна', '+7 (916) 000-12-45', 'petrova.anna@example.com', 'owner', 1, '37', 1],
    'sokolov' => ['Соколов', 'Игорь', 'Петрович', '+7 (916) 211-30-18', null, 'owner', 3, '14', 1],
    'kravtsova' => ['Кравцова', 'Ольга', 'Николаевна', '+7 (926) 340-55-07', 'kravtsova@example.com', 'tenant', 2, '5', 1],
    'lebedev' => ['Лебедев', 'Максим', 'Андреевич', '+7 (903) 455-21-60', null, 'owner', 5, '66', 1],
    'novikova' => ['Новикова', 'Татьяна', 'Ивановна', '+7 (905) 120-48-93', 'novikova.t@example.com', 'owner', 4, '9', 1],
    'egorov' => ['Егоров', 'Виктор', 'Павлович', '+7 (916) 780-14-36', null, 'owner', 1, '12', 0],
    'melnikova' => ['Мельникова', 'Галина', 'Васильевна', '+7 (917) 662-09-81', null, 'owner', 5, '3', 1],
    'orehov' => ['Орехов', 'Денис', 'Олегович', '+7 (925) 301-77-52', 'orehov.d@example.com', 'tenant', 6, '8', 1],
    'belousova' => ['Белоусова', 'Инна', 'Михайловна', '+7 (916) 548-23-19', null, 'owner', 1, '20', 1],
    'tarasov' => ['Тарасов', 'Юрий', 'Геннадьевич', '+7 (909) 217-64-40', null, 'owner', 2, '40', 0],
    'zaytseva' => ['Зайцева', 'Людмила', 'Фёдоровна', '+7 (926) 893-05-12', 'zaytseva@example.com', 'owner', 7, '7', 1],
    'kuznetsova' => ['Кузнецова', 'Вера', 'Алексеевна', '+7 (915) 430-88-26', null, 'tenant', 8, '2', 1],
    'fomin' => ['Фомин', 'Артём', 'Сергеевич', '+7 (916) 094-31-75', null, 'representative', 4, '15', 1],
    'zhukova' => ['Жукова', 'Раиса', 'Степановна', '+7 (903) 576-12-48', null, 'owner', 6, '3', 0],
    'danilov' => ['Данилов', 'Георгий', 'Львович', '+7 (917) 350-92-07', 'danilov.g@example.com', 'owner', 1, '52', 1],
];
foreach ($clients as $k => [$l, $f, $m, $phone, $email, $st, $h, $flat, $consent]) {
    $C[$k] = Db::insert('clients', ['last_name' => $l, 'first_name' => $f, 'middle_name' => $m, 'phone' => $phone, 'email' => $email,
        'status_id' => $cst[$st], 'notify_consent' => $consent, 'pd_consent_at' => at(-60 * 24 * 40), 'created_at' => at(-60 * 24 * 40)]);
    Db::insert('client_premises', ['client_id' => $C[$k], 'premise_id' => $P[$h][$flat], 'since_date' => date('Y-m-d', strtotime('-' . (300 + crc32($k) % 2000) . ' days'))]);
}

// ------------------------------------------------------------ справочник услуг
$sc = [];
$sc['san'] = Db::insert('service_categories', ['parent_id' => null, 'name' => 'Сантехнические работы']);
$sc['elm'] = Db::insert('service_categories', ['parent_id' => null, 'name' => 'Электромонтажные работы']);
$sc['stl'] = Db::insert('service_categories', ['parent_id' => null, 'name' => 'Столярные работы']);
$sc['ubr'] = Db::insert('service_categories', ['parent_id' => null, 'name' => 'Уборка']);
$sc['blg'] = Db::insert('service_categories', ['parent_id' => null, 'name' => 'Благоустройство']);
$sc['vod'] = Db::insert('service_categories', ['parent_id' => $sc['san'], 'name' => 'Водоснабжение и канализация']);
$sc['otp'] = Db::insert('service_categories', ['parent_id' => $sc['san'], 'name' => 'Отопление']);

const NORM_FREE = 'Минимальный перечень услуг и работ по содержанию общего имущества (ПП РФ № 290)';
const BASIS_PAID = 'Заявка собственника, не входящая в перечень обязательных работ';
$services = [
    // код, категория, наименование, ед., норматив ч, цена (null - бесплатная)
    ['САН-001', 'vod', 'Устранение засора канализационного стояка', 'выезд', 3.0, null],
    ['САН-004', 'vod', 'Прочистка канализации внутри квартиры', 'выезд', 2.0, 1200],
    ['САН-009', 'vod', 'Откачка воды из подвала', 'выезд', 4.0, null],
    ['САН-014', 'vod', 'Устранение протечки стояка ХВС', 'выезд', 3.0, null],
    ['САН-016', 'vod', 'Устранение течи трубы ГВС', 'выезд', 3.0, null],
    ['САН-018', 'vod', 'Ремонт запорной арматуры на стояке', 'шт.', 2.0, null],
    ['САН-021', 'vod', 'Замена смесителя в квартире', 'шт.', 1.5, 1800],
    ['САН-025', 'vod', 'Замена участка стояка водоснабжения', 'м', 8.0, null],
    ['ОТП-001', 'otp', 'Спуск воздуха из системы отопления', 'выезд', 1.0, null],
    ['ОТП-005', 'otp', 'Устранение неисправности системы отопления', 'выезд', 4.0, null],
    ['ЭЛМ-002', 'elm', 'Восстановление электроснабжения', 'выезд', 2.0, null],
    ['ЭЛМ-004', 'elm', 'Замена автоматического выключателя в этажном щитке', 'шт.', 1.5, null],
    ['ЭЛМ-007', 'elm', 'Ремонт освещения мест общего пользования', 'выезд', 2.0, null],
    ['ЭЛМ-011', 'elm', 'Замена ламп в подъезде', 'шт.', 0.5, null],
    ['ЭЛМ-012', 'elm', 'Установка розетки или выключателя в квартире', 'шт.', 1.0, 450],
    ['ЭЛМ-015', 'elm', 'Замена этажного электрощитка', 'шт.', 6.0, null],
    ['СТЛ-001', 'stl', 'Ремонт входной двери подъезда', 'выезд', 3.0, null],
    ['СТЛ-003', 'stl', 'Ремонт оконного заполнения в местах общего пользования', 'шт.', 3.0, null],
    ['СТЛ-005', 'stl', 'Ремонт скамеек и ограждений у подъезда', 'выезд', 2.0, null],
    ['СТЛ-009', 'stl', 'Ремонт дверного полотна в квартире', 'шт.', 3.0, 2500],
    ['УБР-001', 'ubr', 'Уборка лестничных клеток', 'м²', 1.0, null],
    ['УБР-002', 'ubr', 'Уборка придомовой территории', 'м²', 2.0, null],
    ['УБР-003', 'ubr', 'Дополнительная влажная уборка по заявке собственника', 'выезд', 1.0, 350],
    ['БЛГ-001', 'blg', 'Ремонт детской площадки', 'выезд', 6.0, null],
    ['БЛГ-002', 'blg', 'Окос травы на придомовой территории', 'м²', 4.0, null],
    ['БЛГ-004', 'blg', 'Спил аварийного дерева', 'шт.', 8.0, null],
];
$S = [];
foreach ($services as [$code, $c, $name, $unit, $hrs, $price]) {
    $S[$code] = Db::insert('services', [
        'code' => $code, 'category_id' => $sc[$c], 'name' => $name, 'description' => null, 'unit' => $unit, 'norm_hours' => $hrs,
        'is_paid' => $price !== null ? 1 : 0, 'price' => $price,
        'basis' => $price !== null ? BASIS_PAID : null, 'norm_document' => $price === null ? NORM_FREE : null,
        'is_archived' => 0, 'created_at' => at(-60 * 24 * 60),
    ]);
}
Db::insert('service_changes', ['service_id' => $S['САН-021'], 'changed_by' => $U['gromov'], 'changed_at' => at(-60 * 24 * 12), 'field_name' => 'price', 'old_value' => '1500.00', 'new_value' => '1800.00']);
Db::insert('service_changes', ['service_id' => $S['УБР-003'], 'changed_by' => $U['gromov'], 'changed_at' => at(-60 * 24 * 20), 'field_name' => 'norm_hours', 'old_value' => '2.0', 'new_value' => '1.0']);

// специализация и привязка к домам
$spec = [
    'smirnov' => ['САН-001', 'САН-004', 'САН-009', 'САН-014', 'САН-016', 'САН-018', 'САН-021', 'ОТП-001', 'ОТП-005'],
    'fedorov' => ['САН-001', 'САН-009', 'САН-014', 'САН-016', 'САН-018', 'САН-025', 'ОТП-001', 'ОТП-005'],
    'ivanov' => ['САН-001', 'САН-004', 'САН-014', 'САН-016', 'САН-018', 'САН-021', 'ОТП-001'],
    'gusev' => ['ЭЛМ-002', 'ЭЛМ-004', 'ЭЛМ-007', 'ЭЛМ-011', 'ЭЛМ-012', 'ЭЛМ-015'],
    'orlov' => ['ЭЛМ-002', 'ЭЛМ-004', 'ЭЛМ-007', 'ЭЛМ-011', 'ЭЛМ-012'],
    'kuzmin' => ['СТЛ-001', 'СТЛ-003', 'СТЛ-005', 'СТЛ-009', 'БЛГ-001'],
    'belova' => ['УБР-001', 'УБР-002', 'УБР-003', 'БЛГ-001', 'БЛГ-002', 'БЛГ-004', 'СТЛ-005'],
    'ivanova' => ['УБР-001', 'УБР-002', 'УБР-003', 'БЛГ-002'],
];
foreach ($spec as $k => $codes) {
    foreach ($codes as $code) {
        Db::insert('employee_services', ['employee_id' => $E[$k], 'service_id' => $S[$code]]);
    }
}
$bind = [
    'smirnov' => [1, 4, 5], 'fedorov' => [2, 3, 4, 6, 7, 8], 'ivanov' => [1, 2, 4, 6, 7],
    'gusev' => [1, 2, 3, 5, 6, 7, 8], 'orlov' => [1, 2, 3, 4, 5, 6, 7, 8], 'kuzmin' => [1, 2, 3, 4, 5, 6, 7, 8],
    'belova' => [1, 2, 3, 4, 5, 6, 7, 8], 'ivanova' => [1, 2, 3, 4, 5, 6, 7, 8],
];
foreach ($bind as $k => $hs) {
    foreach ($hs as $h) {
        Db::insert('employee_houses', ['employee_id' => $E[$k], 'house_id' => $H[$h]]);
    }
}

// правила автоназначения
Db::insert('assignment_rules', ['name' => 'Аварийные заявки (АДС)', 'request_category_id' => (int)$cat['emergency']['id'], 'service_category_id' => null,
    'section_id' => null, 'priority' => 1, 'max_load' => 9, 'allow_off_schedule' => 1, 'is_active' => 1]);
Db::insert('assignment_rules', ['name' => 'Плановые заявки', 'request_category_id' => (int)$cat['planned']['id'], 'service_category_id' => null,
    'section_id' => null, 'priority' => 5, 'max_load' => 6, 'allow_off_schedule' => 0, 'is_active' => 1]);
Db::insert('assignment_rules', ['name' => 'Участок №2: сантехнические работы', 'request_category_id' => null, 'service_category_id' => $sc['san'],
    'section_id' => $sec['Участок №2'], 'priority' => 8, 'max_load' => 9, 'allow_off_schedule' => 0, 'is_active' => 1]);

// ------------------------------------------------------------ заявки
/**
 * Создаёт заявку с историей: new -> accepted -> assigned -> in_progress -> done -> closed (до нужного статуса).
 * Ключи: num, cat, h, flat, client, svc, ch, desc, ago (минут назад), status, who, arrive/done/closed (минут после поступления),
 * manual (в очереди), mode, off, from (прежний исполнитель при переназначении), before/after (число фото), comments, reason.
 */
function mk(array $s): int
{
    global $E, $U, $H, $P, $C, $S, $cat, $stat, $chan, $NOW;
    $received = $NOW - $s['ago'] * 60;
    $rec = date('Y-m-d H:i:s', $received);
    $c = $cat[$s['cat']];
    $dl = DeadlineService::calculate($c, new DateTimeImmutable($rec));
    $disp = $U['morozova'];
    $t = fn(int $afterMin) => date('Y-m-d H:i:s', $received + $afterMin * 60);
    $status = $s['status'];
    $svcRow = Db::one('SELECT is_paid, price, basis FROM services WHERE id = ?', [$S[$s['svc']]]);
    $id = Db::insert('requests', [
        'request_number' => $s['num'], 'received_at' => $rec, 'client_id' => $C[$s['client']], 'house_id' => $H[$s['h']],
        'premise_id' => $s['flat'] !== null ? $P[$s['h']][(string)$s['flat']] : null, 'category_id' => (int)$c['id'], 'service_id' => $S[$s['svc']],
        'channel_id' => $chan[$s['ch']], 'status_id' => $stat['new'], 'description' => $s['desc'], 'created_by' => $disp,
        'arrive_due' => $dl['arrive_due'], 'due_at' => $dl['due_at'], 'warn_at' => $dl['warn_at'], 'updated_at' => $rec,
    ]);
    $hist = function (string $st, ?int $by, int $after, ?string $comment = null) use ($id, $stat, $t) {
        Db::insert('request_status_history', ['request_id' => $id, 'status_id' => $stat[$st], 'changed_by' => $by, 'changed_at' => $t($after), 'comment' => $comment]);
    };
    $hist('new', $disp, 0);
    $arrivedAt = $completedAt = $closedAt = null;
    $assignee = null;
    $reached = ['new' => 0, 'accepted' => 1, 'assigned' => 2, 'in_progress' => 3, 'done' => 4, 'closed' => 5, 'rejected' => 9, 'cancelled' => 9][$status];
    if ($status !== 'new') {
        $hist('accepted', $disp, 1);
    }
    if (isset($s['who']) && $reached >= 2 && $status !== 'rejected' && $status !== 'cancelled') {
        $assignee = $s['who'];
        $manual = ($s['mode'] ?? 'auto') === 'manual';
        if (isset($s['from'])) {
            Db::insert('request_assignments', ['request_id' => $id, 'employee_id' => $E[$s['from']], 'assigned_by' => null, 'mode' => 'auto', 'off_schedule' => 0,
                'assigned_at' => $t(2), 'unassigned_at' => $t(40), 'reason' => null]);
            $hist('assigned', null, 2, 'автоназначение');
            Db::insert('request_assignments', ['request_id' => $id, 'employee_id' => $E[$assignee], 'assigned_by' => $disp, 'mode' => 'manual', 'off_schedule' => 0,
                'assigned_at' => $t(41), 'unassigned_at' => null, 'reason' => $s['reason'] ?? 'Переназначение']);
        } else {
            Db::insert('request_assignments', ['request_id' => $id, 'employee_id' => $E[$assignee], 'assigned_by' => $manual ? $disp : null,
                'mode' => $manual ? 'manual' : 'auto', 'off_schedule' => !empty($s['off']) ? 1 : 0, 'assigned_at' => $t(2), 'unassigned_at' => null, 'reason' => null]);
            $hist('assigned', $manual ? $disp : null, 2, $manual ? 'назначено диспетчером' : 'автоназначение');
        }
    }
    if ($reached >= 3 && $reached <= 5 && isset($s['arrive'])) {
        $hist('in_progress', $U[$assignee], $s['arrive']);
        $arrivedAt = $t($s['arrive']);
    }
    if ($reached >= 4 && $reached <= 5) {
        $hist('done', $U[$assignee], $s['done']);
        $completedAt = $t($s['done']);
    }
    for ($i = 1; $i <= ($s['before'] ?? 0); $i++) {
        $f = sprintf('storage/photos/%s/%d/before_%d.jpg', date('Y', $received), $id, $i);
        placeholder(APP_ROOT . '/' . $f, 'foto do ' . $i);
        $real = is_file(APP_ROOT . '/' . $f) ? $f : substr($f, 0, -4) . '.png';
        Db::insert('request_photos', ['request_id' => $id, 'uploaded_by' => $disp, 'stage' => 'initial', 'file_name' => basename($real),
            'file_path' => substr($real, strlen('storage/photos/')), 'file_size' => filesize(APP_ROOT . '/' . $real), 'uploaded_at' => $t(3 + $i),
            'retention_until' => date('Y-m-d', strtotime('+3 years', $received))]);
    }
    for ($i = 1; $i <= ($s['after'] ?? 0); $i++) {
        $f = sprintf('storage/photos/%s/%d/after_%d.jpg', date('Y', $received), $id, $i);
        placeholder(APP_ROOT . '/' . $f, 'foto posle ' . $i);
        $real = is_file(APP_ROOT . '/' . $f) ? $f : substr($f, 0, -4) . '.png';
        Db::insert('request_photos', ['request_id' => $id, 'uploaded_by' => $U[$assignee], 'stage' => 'final', 'file_name' => basename($real),
            'file_path' => substr($real, strlen('storage/photos/')), 'file_size' => filesize(APP_ROOT . '/' . $real),
            'uploaded_at' => $t(($s['done'] ?? 60) - 5), 'retention_until' => date('Y-m-d', strtotime('+3 years', $received))]);
    }
    foreach ($s['comments'] ?? [] as [$after, $by, $text]) {
        Db::insert('request_comments', ['request_id' => $id, 'author_id' => $U[$by], 'text' => $text, 'is_additional_contact' => 0, 'created_at' => $t($after)]);
    }
    if ($reached >= 5 && $status === 'closed') {
        $hist('closed', $disp, $s['closed']);
        $closedAt = $t($s['closed']);
    }
    if ($status === 'rejected') {
        $hist('rejected', $disp, 10, $s['reason']);
    }
    if ($status === 'cancelled') {
        $hist('cancelled', $disp, 25, $s['reason']);
    }
    $isFinal = in_array($status, ['closed', 'rejected', 'cancelled'], true);
    $nowS = date('Y-m-d H:i:s', $NOW);
    $overdue = $completedAt !== null ? $completedAt > $dl['due_at'] : (!$isFinal && $dl['due_at'] < $nowS);
    $upd = [
        'status_id' => $stat[$status], 'assignee_id' => $assignee !== null ? $E[$assignee] : null,
        'manual_assign_required' => !empty($s['manual']) ? 1 : 0, 'arrived_at' => $arrivedAt, 'completed_at' => $completedAt, 'closed_at' => $closedAt,
        'is_overdue' => $overdue && $status !== 'rejected' && $status !== 'cancelled' ? 1 : 0,
        'warning_sent' => (!$isFinal && $status !== 'done' && $dl['warn_at'] <= $nowS) || ($completedAt !== null && $dl['warn_at'] <= $completedAt) ? 1 : 0,
        'updated_at' => $closedAt ?? $completedAt ?? $arrivedAt ?? $rec,
    ];
    Db::update('requests', $upd, 'id = ?', [$id]);
    if ($status === 'closed') {
        if ((int)$svcRow['is_paid']) {
            Db::insert('charges', ['request_id' => $id, 'service_id' => $S[$s['svc']], 'amount' => $svcRow['price'], 'basis' => $svcRow['basis'],
                'status' => 'accrued', 'created_by' => $disp, 'created_at' => $closedAt]);
        }
        foreach ($s['extra'] ?? [] as [$after, $by, $text]) {
            Db::insert('request_comments', ['request_id' => $id, 'author_id' => $U[$by], 'text' => $text, 'is_additional_contact' => 1, 'created_at' => $t($after)]);
        }
    }
    // уведомления: исполнителю (сайт) и клиенту (SMS при согласии) - только для открытых заявок
    if (!$isFinal) {
        if ($assignee !== null && $reached >= 2) {
            Db::insert('notifications', ['recipient_type' => 'employee', 'employee_id' => $E[$assignee], 'request_id' => $id, 'channel' => 'site', 'type' => 'new_request',
                'message' => 'Вам назначена заявка ' . $s['num'] . ' (срок устранения ' . date('d.m.Y H:i', strtotime($dl['due_at'])) . ')',
                'status' => $reached >= 3 ? 'read' : 'new', 'created_at' => $t(2), 'sent_at' => null, 'read_at' => $reached >= 3 ? $t(($s['arrive'] ?? 5)) : null]);
        }
        if ((int)Db::val('SELECT notify_consent FROM clients WHERE id = ?', [$C[$s['client']]])) {
            $text = 'ПосадДом: заявка ' . $s['num'] . ' принята. Срок устранения: ' . date('d.m.Y H:i', strtotime($dl['due_at'])) . '.';
            $nid = Db::insert('notifications', ['recipient_type' => 'client', 'client_id' => $C[$s['client']], 'request_id' => $id, 'channel' => 'sms', 'type' => 'request_registered',
                'message' => $text, 'status' => 'sent', 'created_at' => $t(1), 'sent_at' => $t(1)]);
            Db::insert('integration_queue', ['system' => 'sms', 'request_id' => $id, 'direction' => 'out', 'payload' => json_encode(['to' => 'demo', 'text' => $text, 'notification_id' => $nid], JSON_UNESCAPED_UNICODE),
                'status' => 'sent', 'attempts' => 1, 'next_try_at' => null, 'created_at' => $t(1), 'processed_at' => $t(1)]);
        }
    }
    if ($s['cat'] === 'emergency') {
        $pending = !empty($s['rso_pending']);
        Db::insert('integration_queue', ['system' => 'rso', 'request_id' => $id, 'direction' => 'out',
            'payload' => json_encode(['event' => 'emergency_request', 'number' => $s['num']], JSON_UNESCAPED_UNICODE),
            'status' => $pending ? 'pending' : 'sent', 'attempts' => $pending ? 2 : 1, 'last_error' => $pending ? 'Внешний сервис недоступен' : null,
            'next_try_at' => $pending ? at(4) : null, 'created_at' => $t(1), 'processed_at' => $pending ? null : $t(1)]);
    }
    if ($s['ch'] === 'gis_gkh') {
        Db::insert('integration_queue', ['system' => 'gis_gkh', 'request_id' => $id, 'direction' => 'in',
            'payload' => json_encode(['event' => 'request_received', 'number' => $s['num']], JSON_UNESCAPED_UNICODE),
            'status' => 'sent', 'attempts' => 1, 'next_try_at' => null, 'created_at' => $t(0), 'processed_at' => $t(0)]);
    }
    return $id;
}

$R = [];
// --- открытые заявки (в порядке макета)
$R['A138'] = mk(['num' => 'А-2026-000138', 'cat' => 'emergency', 'h' => 3, 'flat' => 14, 'client' => 'sokolov', 'svc' => 'ЭЛМ-002', 'ch' => 'phone',
    'desc' => 'Нет электричества во всей квартире, у соседей свет есть', 'ago' => 26 * 60, 'status' => 'in_progress', 'who' => 'gusev', 'arrive' => 22, 'before' => 1,
    'comments' => [[40, 'gusev', 'Проверил вводной автомат, неисправен общий щиток этажа. Заказал запчасти.']]]);
$R['A139'] = mk(['num' => 'А-2026-000139', 'cat' => 'emergency', 'h' => 2, 'flat' => 5, 'client' => 'kravtsova', 'svc' => 'САН-001', 'ch' => 'phone',
    'desc' => 'Засор стояка канализации, нечистоты поднимаются в санузле', 'ago' => 25 * 60, 'status' => 'in_progress', 'who' => 'fedorov', 'arrive' => 18, 'before' => 2,
    'comments' => [[30, 'fedorov', 'Прочистка тросом не помогла, готовим гидродинамическую машину.']]]);
$R['A140'] = mk(['num' => 'А-2026-000140', 'cat' => 'emergency', 'h' => 5, 'flat' => null, 'client' => 'lebedev', 'svc' => 'САН-016', 'ch' => 'phone',
    'desc' => 'Течь трубы ГВС в подвале, вода на полу', 'ago' => 22 * 60 + 20, 'status' => 'in_progress', 'who' => 'smirnov', 'arrive' => 25, 'before' => 1]);
$R['A143'] = mk(['num' => 'А-2026-000143', 'cat' => 'emergency', 'h' => 8, 'flat' => 31, 'client' => 'kuznetsova', 'svc' => 'ЭЛМ-002', 'ch' => 'phone',
    'desc' => 'Выбивает автомат, пахнет гарью из щитка', 'ago' => 12, 'status' => 'assigned', 'who' => 'gusev', 'before' => 0]);
$R['A144'] = mk(['num' => 'А-2026-000144', 'cat' => 'emergency', 'h' => 4, 'flat' => 9, 'client' => 'novikova', 'svc' => 'ОТП-005', 'ch' => 'phone',
    'desc' => 'В квартире холодные батареи, в стояке нет циркуляции', 'ago' => 3 * 60, 'status' => 'assigned', 'who' => 'fedorov']);
$R['A145'] = mk(['num' => 'А-2026-000145', 'cat' => 'emergency', 'h' => 1, 'flat' => 37, 'client' => 'petrova', 'svc' => 'САН-014', 'ch' => 'phone',
    'desc' => 'Течёт стояк ХВС в санузле, вода на полу', 'ago' => 2 * 60 + 28, 'status' => 'in_progress', 'who' => 'smirnov', 'arrive' => 19, 'before' => 2,
    'comments' => [[35, 'smirnov', 'Перекрыл стояк, жду замену хомута'], [43, 'morozova', 'Клиенту отправлено SMS'], [128, 'smirnov', 'Хомут получен, начинаю замену']]]);
$R['A147'] = mk(['num' => 'А-2026-000147', 'cat' => 'emergency', 'h' => 1, 'flat' => null, 'client' => 'egorov', 'svc' => 'САН-009', 'ch' => 'gis_gkh',
    'desc' => 'Подвал затоплен после прорыва, вода по щиколотку (обращение из ГИС ЖКХ)', 'ago' => 26, 'status' => 'new', 'manual' => true, 'rso_pending' => true]);
$R['C421'] = mk(['num' => 'С-2026-000421', 'cat' => 'urgent', 'h' => 4, 'flat' => null, 'client' => 'novikova', 'svc' => 'ЭЛМ-007', 'ch' => 'site',
    'desc' => 'Не горит освещение на лестничной клетке 2 подъезда, темно на всех этажах', 'ago' => 95, 'status' => 'accepted', 'manual' => true]);
$R['C412'] = mk(['num' => 'С-2026-000412', 'cat' => 'urgent', 'h' => 5, 'flat' => 66, 'client' => 'lebedev', 'svc' => 'ЭЛМ-004', 'ch' => 'phone',
    'desc' => 'Выбивает автомат на этаже при включении нагрузки', 'ago' => 22 * 60, 'status' => 'in_progress', 'who' => 'gusev', 'arrive' => 70, 'before' => 1]);
$R['P927'] = mk(['num' => 'П-2026-000927', 'cat' => 'planned', 'h' => 2, 'flat' => null, 'client' => 'kravtsova', 'svc' => 'СТЛ-003', 'ch' => 'email',
    'desc' => 'Разбито стекло в окне на лестничной площадке 2 подъезда', 'ago' => 14 * 60, 'status' => 'assigned', 'who' => 'kuzmin']);
$R['C415'] = mk(['num' => 'С-2026-000415', 'cat' => 'urgent', 'h' => 1, 'flat' => 20, 'client' => 'belousova', 'svc' => 'САН-018', 'ch' => 'phone',
    'desc' => 'Течёт вентиль на стояке под раковиной', 'ago' => 5 * 60, 'status' => 'assigned', 'who' => 'smirnov']);
$R['P931'] = mk(['num' => 'П-2026-000931', 'cat' => 'planned', 'h' => 1, 'flat' => 52, 'client' => 'danilov', 'svc' => 'ЭЛМ-012', 'ch' => 'site',
    'desc' => 'Установить дополнительную розетку на кухне', 'ago' => 3 * 60, 'status' => 'assigned', 'who' => 'gusev']);
$R['P929'] = mk(['num' => 'П-2026-000929', 'cat' => 'planned', 'h' => 7, 'flat' => null, 'client' => 'zaytseva', 'svc' => 'УБР-001', 'ch' => 'phone',
    'desc' => 'Грязные лестничные клетки в 1 подъезде, требуется влажная уборка', 'ago' => 30 * 60, 'status' => 'in_progress', 'who' => 'belova', 'from' => 'ivanova',
    'reason' => 'Исполнитель в отпуске', 'arrive' => 22 * 60]);
$R['C418'] = mk(['num' => 'С-2026-000418', 'cat' => 'urgent', 'h' => 6, 'flat' => null, 'client' => 'orehov', 'svc' => 'СТЛ-001', 'ch' => 'phone',
    'desc' => 'Не закрывается входная дверь подъезда, сломан доводчик', 'ago' => 30 * 60, 'status' => 'in_progress', 'who' => 'kuzmin', 'arrive' => 90, 'before' => 1]);
$R['P925'] = mk(['num' => 'П-2026-000925', 'cat' => 'planned', 'h' => 8, 'flat' => null, 'client' => 'kuznetsova', 'svc' => 'БЛГ-002', 'ch' => 'site',
    'desc' => 'Высокая трава у детской площадки, нужен окос', 'ago' => 50 * 60, 'status' => 'assigned', 'who' => 'belova']);
$R['C419'] = mk(['num' => 'С-2026-000419', 'cat' => 'urgent', 'h' => 4, 'flat' => 15, 'client' => 'fomin', 'svc' => 'ОТП-001', 'ch' => 'phone',
    'desc' => 'Шумит и не греет радиатор в комнате, нужно спустить воздух', 'ago' => 4 * 60, 'status' => 'assigned', 'who' => 'ivanov', 'mode' => 'manual']);
$R['P933'] = mk(['num' => 'П-2026-000933', 'cat' => 'planned', 'h' => 5, 'flat' => 12, 'client' => 'melnikova', 'svc' => 'САН-021', 'ch' => 'phone',
    'desc' => 'Заменить смеситель на кухне (платная услуга)', 'ago' => 90, 'status' => 'assigned', 'who' => 'ivanov']);
$R['P928'] = mk(['num' => 'П-2026-000928', 'cat' => 'planned', 'h' => 2, 'flat' => null, 'client' => 'tarasov', 'svc' => 'СТЛ-005', 'ch' => 'phone',
    'desc' => 'Сломана скамейка у 3 подъезда', 'ago' => 20 * 60, 'status' => 'in_progress', 'who' => 'kuzmin', 'arrive' => 17 * 60]);
$R['C420'] = mk(['num' => 'С-2026-000420', 'cat' => 'urgent', 'h' => 6, 'flat' => 3, 'client' => 'zhukova', 'svc' => 'САН-004', 'ch' => 'phone',
    'desc' => 'Засорилась канализация на кухне (платная услуга)', 'ago' => 6 * 60, 'status' => 'assigned', 'who' => 'ivanov']);
$R['P930'] = mk(['num' => 'П-2026-000930', 'cat' => 'planned', 'h' => 7, 'flat' => null, 'client' => 'zaytseva', 'svc' => 'БЛГ-001', 'ch' => 'site',
    'desc' => 'Требуется ремонт качелей на детской площадке', 'ago' => 8 * 60, 'status' => 'assigned', 'who' => 'belova']);
// --- выполнены, ожидают закрытия
$R['A141'] = mk(['num' => 'А-2026-000141', 'cat' => 'emergency', 'h' => 6, 'flat' => 8, 'client' => 'orehov', 'svc' => 'ЭЛМ-002', 'ch' => 'phone',
    'desc' => 'Пропало электричество в квартире', 'ago' => 7 * 60, 'status' => 'done', 'who' => 'gusev', 'arrive' => 24, 'done' => 150, 'before' => 1, 'after' => 2,
    'comments' => [[150, 'gusev', 'Заменён автомат, напряжение в норме']]]);
$R['P926'] = mk(['num' => 'П-2026-000926', 'cat' => 'planned', 'h' => 3, 'flat' => null, 'client' => 'sokolov', 'svc' => 'СТЛ-001', 'ch' => 'phone',
    'desc' => 'Ремонт двери в тамбуре 1 подъезда', 'ago' => 3 * 24 * 60, 'status' => 'done', 'who' => 'kuzmin', 'arrive' => 20 * 60, 'done' => 2 * 24 * 60, 'before' => 1, 'after' => 1]);
// --- отклонена и отменена
$R['P921'] = mk(['num' => 'П-2026-000921', 'cat' => 'planned', 'h' => 5, 'flat' => 3, 'client' => 'melnikova', 'svc' => 'СТЛ-003', 'ch' => 'phone',
    'desc' => 'Заменить окно в собственной квартире', 'ago' => 3 * 24 * 60, 'status' => 'rejected', 'reason' => 'Остекление квартиры - зона ответственности собственника']);
$R['C416'] = mk(['num' => 'С-2026-000416', 'cat' => 'urgent', 'h' => 1, 'flat' => 12, 'client' => 'egorov', 'svc' => 'САН-016', 'ch' => 'phone',
    'desc' => 'Капает труба ГВС в ванной', 'ago' => 2 * 24 * 60, 'status' => 'cancelled', 'who' => 'smirnov', 'reason' => 'Клиент отозвал заявку: устранил самостоятельно']);
// --- закрытые (12)
$closed = [
    ['A130', 'А-2026-000130', 'emergency', 1, 12, 'egorov', 'САН-014', 'Течёт стояк ХВС', 8 * 1440, 'smirnov', 19, 150, 190, 1, 2],
    ['A131', 'А-2026-000131', 'emergency', 5, 3, 'melnikova', 'ЭЛМ-002', 'Нет света в квартире', 7 * 1440 + 300, 'gusev', 22, 120, 160, 1, 1],
    ['A133', 'А-2026-000133', 'emergency', 2, null, 'kravtsova', 'САН-009', 'Затопило подвал после ливня', 6 * 1440, 'fedorov', 26, 26 * 60, 28 * 60, 2, 2],
    ['C405', 'С-2026-000405', 'urgent', 1, 37, 'petrova', 'САН-016', 'Течь трубы ГВС в ванной', 6 * 1440 + 120, 'smirnov', 70, 5 * 60, 6 * 60, 1, 1],
    ['C406', 'С-2026-000406', 'urgent', 6, null, 'orehov', 'ЭЛМ-007', 'Не горит свет в подъезде', 5 * 1440, 'gusev', 80, 9 * 60, 10 * 60, 1, 1],
    ['C408', 'С-2026-000408', 'urgent', 3, null, 'sokolov', 'ОТП-005', 'Не греют батареи на этаже', 5 * 1440 + 200, 'fedorov', 60, 30 * 60, 31 * 60, 1, 2],
    ['P905', 'П-2026-000905', 'planned', 1, 20, 'belousova', 'САН-021', 'Замена смесителя в ванной', 9 * 1440, 'smirnov', 26 * 60, 3 * 1440, 3 * 1440 + 90, 1, 1],
    ['P908', 'П-2026-000908', 'planned', 5, 18, 'lebedev', 'УБР-003', 'Дополнительная уборка после ремонта', 8 * 1440, 'belova', 26 * 60, 2 * 1440, 2 * 1440 + 60, 0, 1],
    ['P910', 'П-2026-000910', 'planned', 2, 40, 'tarasov', 'СТЛ-009', 'Ремонт дверного полотна в комнате', 7 * 1440 + 90, 'kuzmin', 26 * 60, 4 * 1440, 4 * 1440 + 100, 1, 2],
    ['P912', 'П-2026-000912', 'planned', 7, 7, 'zaytseva', 'ЭЛМ-012', 'Установка двух розеток', 6 * 1440 + 60, 'gusev', 28 * 60, 3 * 1440, 3 * 1440 + 30, 1, 1],
    ['P915', 'П-2026-000915', 'planned', 8, null, 'kuznetsova', 'СТЛ-001', 'Ремонт двери подъезда', 4 * 1440, 'kuzmin', 26 * 60, 2 * 1440, 2 * 1440 + 60, 1, 1],
    ['C411', 'С-2026-000411', 'urgent', 4, 15, 'fomin', 'САН-004', 'Засор канализации в квартире', 3 * 1440, 'ivanov', 3 * 60, 12 * 60, 13 * 60, 1, 1],
];
foreach ($closed as [$key, $num, $cc, $h, $flat, $client, $svc, $desc, $ago, $who, $arrive, $done, $closeAt, $b, $a]) {
    $R[$key] = mk(['num' => $num, 'cat' => $cc, 'h' => $h, 'flat' => $flat, 'client' => $client, 'svc' => $svc, 'ch' => 'phone', 'desc' => $desc, 'ago' => $ago,
        'status' => 'closed', 'who' => $who, 'arrive' => $arrive, 'done' => $done, 'closed' => $closeAt, 'before' => $b, 'after' => max(1, $a),
        'comments' => [[$done - 10, $who, 'Работы выполнены, мусор убран']]]);
}
// дополнительное обращение после закрытия
Db::insert('request_comments', ['request_id' => $R['A130'], 'author_id' => $U['morozova'], 'text' => 'Клиент перезвонил: пятно на потолке, передано мастеру участка', 'is_additional_contact' => 1, 'created_at' => at(-7 * 1440)]);

// счётчик нумерации: сквозной, продолжается после наибольшего номера
$maxN = 0;
foreach (Db::all('SELECT request_number FROM requests') as $r) {
    $maxN = max($maxN, (int)substr($r['request_number'], -6));
}
Db::exec('INSERT INTO request_number_seq (`year`, last_value) VALUES (?, ?)', [(int)date('Y'), $maxN]);

// ------------------------------------------------------------ уведомления руководству и диспетчеру, журнал аудита
$notes = [
    ['vasilyev', 'overdue', 'A138', 'Заявка А-2026-000138 просрочена', -120, 'new'],
    ['vasilyev', 'overdue', 'A139', 'Заявка А-2026-000139 просрочена', -60, 'new'],
    ['gusev', 'overdue', 'A138', 'Заявка А-2026-000138 просрочена', -120, 'new'],
    ['fedorov', 'overdue', 'A139', 'Заявка А-2026-000139 просрочена', -60, 'new'],
    ['morozova', 'deadline_warning', 'A140', 'Заявка А-2026-000140: до срока устранения осталось менее 25 % времени', -100, 'read'],
    ['smirnov', 'deadline_warning', 'A140', 'Заявка А-2026-000140: до срока устранения осталось менее 25 % времени', -100, 'new'],
    ['morozova', 'queued', 'A147', 'Заявка А-2026-000147 не распределена автоматически: требуется назначение вручную', -25, 'new'],
    ['morozova', 'queued', 'C421', 'Заявка С-2026-000421 не распределена автоматически: требуется назначение вручную', -94, 'new'],
    ['morozova', 'status_changed', 'A141', 'Заявка А-2026-000141 выполнена исполнителем Гусев Д.И.: проверьте и закройте', -270, 'new'],
    ['gusev', 'off_schedule', 'A143', 'Вызов вне графика: аварийная заявка А-2026-000143', -11, 'new'],
];
foreach ($notes as [$who, $type, $rk, $msg, $min, $st]) {
    Db::insert('notifications', ['recipient_type' => 'employee', 'employee_id' => $E[$who], 'request_id' => $R[$rk], 'channel' => 'site', 'type' => $type,
        'message' => $msg, 'status' => $st, 'created_at' => at($min), 'read_at' => $st === 'read' ? at($min + 5) : null]);
}
Db::exec('UPDATE request_assignments SET off_schedule = 1 WHERE request_id = ?', [$R['A143']]);
$audit = [
    ['morozova', 'auth.login', 'user', 'morozova', 600], ['vasilyev', 'auth.login', 'user', 'vasilyev', 590], ['smirnov', 'auth.login', 'user', 'smirnov', 400],
    ['gromov', 'auth.login', 'user', 'gromov', 1500], ['gromov', 'service.update', 'service', 'САН-021', 1440 * 12],
];
foreach ($audit as [$who, $action, $et, $ent, $min]) {
    Db::insert('audit_log', ['user_id' => $U[$who], 'action' => $action, 'entity_type' => $et,
        'entity_id' => $et === 'user' ? $U[$ent] : $S[$ent], 'old_value' => null, 'new_value' => null, 'ip_address' => '127.0.0.1', 'created_at' => at(-$min)]);
}
Db::insert('audit_log', ['user_id' => $U['morozova'], 'action' => 'request.create', 'entity_type' => 'request', 'entity_id' => $R['A145'], 'old_value' => null,
    'new_value' => json_encode(['number' => 'А-2026-000145'], JSON_UNESCAPED_UNICODE), 'ip_address' => '127.0.0.1', 'created_at' => at(-148)]);

echo 'Демо-данные: ' . Db::val('SELECT COUNT(*) FROM requests') . " заявок, " . Db::val('SELECT COUNT(*) FROM employees') . " сотрудников, "
    . Db::val('SELECT COUNT(*) FROM services') . " услуг, пароль всех пользователей: " . DEMO_PASSWORD . "\n";
