<?php
// Модульные проверки DeadlineService, NumberingService, AssignmentService без HTTP:  php tests/unit_test.php
// Нужна установленная БД (database/install.php --demo). Все изменения БД выполняются в транзакции и откатываются.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Db;
use App\Services\AssignmentService;
use App\Services\DeadlineService;
use App\Services\NumberingService;

$fail = 0;
$ok = 0;
function check(string $name, bool $cond, string $info = ''): void
{
    global $fail, $ok;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . ($cond ? '' : " $info") . "\n";
    $cond ? $ok++ : $fail++;
}
function d(string $s): DateTimeImmutable { return new DateTimeImmutable($s); }

$S = ['business_start' => '09:00', 'business_end' => '18:00', 'warn_percent' => '25'];
$cats = [];
foreach (Db::all('SELECT * FROM request_categories') as $c) {
    $cats[$c['code']] = $c;
}

echo "== DeadlineService\n";
$r = DeadlineService::calculate($cats['emergency'], d('2026-11-04 08:12:00'), $S);
check('аварийная: выезд +30 мин', $r['arrive_due'] === '2026-11-04 08:42:00', $r['arrive_due']);
check('аварийная: устранение +24 ч', $r['due_at'] === '2026-11-05 08:12:00', $r['due_at']);
check('аварийная: предупреждение через 18 ч (02:12)', $r['warn_at'] === '2026-11-05 02:12:00', $r['warn_at']);
$r = DeadlineService::calculate($cats['urgent'], d('2026-11-04 09:05:00'), $S);
check('срочная: выезд +2 ч', $r['arrive_due'] === '2026-11-04 11:05:00', $r['arrive_due']);
check('срочная: устранение +72 ч', $r['due_at'] === '2026-11-07 09:05:00', $r['due_at']);
$r = DeadlineService::calculate($cats['planned'], d('2026-11-13 16:00:00'), $S); // пятница
check('плановая в пятницу 16:00: выезд в понедельник 16:00', $r['arrive_due'] === '2026-11-16 16:00:00', $r['arrive_due']);
check('плановая: устранение +168 ч (7 суток)', $r['due_at'] === '2026-11-20 16:00:00', $r['due_at']);
$r = DeadlineService::calculate($cats['planned'], d('2026-11-11 10:30:00'), $S); // среда
check('плановая в среду 10:30: выезд в четверг 10:30', $r['arrive_due'] === '2026-11-12 10:30:00', $r['arrive_due']);
$r = DeadlineService::calculate($cats['planned'], d('2026-11-14 10:00:00'), $S); // суббота
check('плановая в субботу 10:00: выезд в понедельник 10:00', $r['arrive_due'] === '2026-11-16 10:00:00', $r['arrive_due']);
$r = DeadlineService::calculate($cats['planned'], d('2026-11-12 20:00:00'), $S); // позже конца рабочего дня
check('плановая в четверг 20:00: выезд в пятницу не позже 18:00', $r['arrive_due'] === '2026-11-13 18:00:00', $r['arrive_due']);
$r = DeadlineService::calculate($cats['planned'], d('2026-11-12 07:00:00'), $S);
check('плановая в 07:00: выезд не раньше 09:00', $r['arrive_due'] === '2026-11-13 09:00:00', $r['arrive_due']);
$r = DeadlineService::calculate($cats['emergency'], d('2026-11-04 08:00:00'), array_merge($S, ['warn_percent' => '50']));
check('warn_percent=50: предупреждение через половину срока', $r['warn_at'] === '2026-11-04 20:00:00', $r['warn_at']);
$now = d('2026-11-12 12:00:00');
check('остаток до срока: +60 мин', DeadlineService::minutesLeft('2026-11-12 13:00:00', $now) === 60);
check('срок прошёл: отрицательный остаток', DeadlineService::minutesLeft('2026-11-12 11:30:00', $now) === -30);
check('просрочка 120 мин', DeadlineService::overdueMinutes('2026-11-12 10:00:00', null, $now) === 120);
check('нет просрочки до срока', DeadlineService::overdueMinutes('2026-11-12 13:00:00', null, $now) === null);
check('выполнена в срок: просрочки нет', DeadlineService::overdueMinutes('2026-11-12 10:00:00', '2026-11-12 09:50:00', $now) === null);
check('выполнена позже срока: просрочка 45 мин', DeadlineService::overdueMinutes('2026-11-12 10:00:00', '2026-11-12 10:45:00', $now) === 45);
check('предупреждение наступило', DeadlineService::needsWarning('2026-11-12 11:59:00', $now) && !DeadlineService::needsWarning('2026-11-12 12:01:00', $now));

echo "== NumberingService\n";
$pdo = Db::pdo();
$pdo->beginTransaction();
check('формат номера', NumberingService::format('А', 2026, 145) === 'А-2026-000145');
$year = 2098;
$a = NumberingService::next('А', $year);
$b = NumberingService::next('С', $year);
$c = NumberingService::next('П', $year);
check('новый год начинается с 000001', $a === 'А-2098-000001', $a);
check('нумерация сквозная для всех категорий', $b === 'С-2098-000002' && $c === 'П-2098-000003', "$b $c");
check('peek не меняет счётчик', NumberingService::peek('А', $year) === 'А-2098-000004' && NumberingService::peek('А', $year) === 'А-2098-000004');
check('счётчик в БД = 3', (int)Db::val('SELECT last_value FROM request_number_seq WHERE `year` = ?', [$year]) === 3);
$other = NumberingService::next('А', 2099);
check('счётчики разных лет независимы', $other === 'А-2099-000001', $other);
$pdo->rollBack();
check('откат транзакции убирает счётчик', Db::val('SELECT COUNT(*) FROM request_number_seq WHERE `year` IN (2098, 2099)') == 0);

echo "== AssignmentService\n";
$pdo->beginTransaction();
Db::exec('UPDATE assignment_rules SET is_active = 0');
$now = date('Y-m-d H:i:s');
$posId = (int)Db::val("SELECT id FROM positions WHERE name = 'Слесарь-сантехник'");
$active = (int)Db::val("SELECT id FROM employee_statuses WHERE code = 'active'");
$vac = (int)Db::val("SELECT id FROM employee_statuses WHERE code = 'vacation'");
$five = (int)Db::val("SELECT id FROM schedule_types WHERE code = 'five_day'");
$round = (int)Db::val("SELECT id FROM schedule_types WHERE code = 'round_clock'");
$shift = (int)Db::val("SELECT id FROM schedule_types WHERE code = 'shift'");
$secId = (int)Db::val('SELECT id FROM sections ORDER BY id LIMIT 1');
$svcCat = (int)Db::insert('service_categories', ['parent_id' => null, 'name' => 'Тест-категория']);
$house = Db::insert('houses', ['section_id' => $secId, 'address' => 'ул. Тестовая, 1', 'floors' => 5, 'entrances' => 1, 'premises_count' => 10, 'year_built' => 2000, 'is_active' => 1]);
$svc = Db::insert('services', ['code' => 'ТСТ-001', 'category_id' => $svcCat, 'name' => 'Тестовая услуга', 'unit' => 'выезд', 'norm_hours' => 1, 'is_paid' => 0, 'norm_document' => 'тест', 'created_at' => $now]);
$cst = (int)Db::val('SELECT id FROM client_statuses LIMIT 1');
$client = Db::insert('clients', ['last_name' => 'Тестов', 'first_name' => 'Тест', 'phone' => '+7 (900) 000-00-00', 'status_id' => $cst, 'pd_consent_at' => $now, 'created_at' => $now]);
$user = (int)Db::val('SELECT id FROM users ORDER BY id LIMIT 1');
$mkEmp = function (string $name, int $status, int $sched, bool $bind = true) use ($posId, $now, $svc, $house) {
    $id = Db::insert('employees', ['last_name' => $name, 'first_name' => 'Т', 'position_id' => $posId, 'status_id' => $status, 'schedule_type_id' => $sched,
        'phone' => '+7 (900) 111-11-11', 'hire_date' => '2020-01-01', 'created_at' => $now]);
    if ($bind) {
        Db::insert('employee_services', ['employee_id' => $id, 'service_id' => $svc]);
        Db::insert('employee_houses', ['employee_id' => $id, 'house_id' => $house]);
    }
    return $id;
};
$mkReq = function (int $emp, string $category, string $status) use ($client, $house, $svc, $user, $now) {
    $cat = Db::one('SELECT id FROM request_categories WHERE code = ?', [$category]);
    return Db::insert('requests', ['request_number' => 'Т-' . random_int(100000, 999999) . '-' . random_int(100000, 999999), 'received_at' => $now,
        'client_id' => $client, 'house_id' => $house, 'category_id' => (int)$cat['id'], 'service_id' => $svc,
        'channel_id' => (int)Db::val("SELECT id FROM request_channels WHERE code = 'phone'"), 'status_id' => (int)Db::val('SELECT id FROM request_statuses WHERE code = ?', [$status]),
        'description' => 'тест', 'assignee_id' => $emp, 'created_by' => $user, 'arrive_due' => $now, 'due_at' => $now, 'warn_at' => $now, 'updated_at' => $now]);
};
$setSched = function (int $emp, array $days, string $type, string $from, string $to) {
    foreach ($days as $d) {
        Db::insert('work_schedules', ['employee_id' => $emp, 'weekday' => $d, 'shift_type' => $type, 'start_time' => $from, 'end_time' => $to]);
    }
};
$mon10 = d('2026-11-16 10:00:00');   // понедельник
$sat10 = d('2026-11-14 10:00:00');   // суббота
$A = $mkEmp('Альфа', $active, $five);
$B = $mkEmp('Бета', $active, $five);
$setSched($A, [1, 2, 3, 4, 5], 'day', '09:00:00', '18:00:00');
$setSched($B, [1, 2, 3, 4, 5], 'day', '09:00:00', '18:00:00');

check('пятидневка: понедельник 10:00 в смене', AssignmentService::isOnSchedule($A, 'five_day', $mon10));
check('пятидневка: понедельник 19:00 вне смены', !AssignmentService::isOnSchedule($A, 'five_day', d('2026-11-16 19:00:00')));
check('пятидневка: суббота вне смены', !AssignmentService::isOnSchedule($A, 'five_day', $sat10));
Db::insert('schedule_exceptions', ['employee_id' => $B, 'exception_date' => '2026-11-16', 'is_day_off' => 1, 'reason' => 'отгул']);
check('исключение: выходной день снимает смену', !AssignmentService::isOnSchedule($B, 'five_day', $mon10));
Db::insert('schedule_exceptions', ['employee_id' => $A, 'exception_date' => '2026-11-17', 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'is_day_off' => 0]);
check('исключение: укороченный день (11:00 в смене, 13:00 нет)',
    AssignmentService::isOnSchedule($A, 'five_day', d('2026-11-17 11:00:00')) && !AssignmentService::isOnSchedule($A, 'five_day', d('2026-11-17 13:00:00')));
$N = $mkEmp('Ночной', $active, $shift, false);
$setSched($N, [1], 'night', '22:00:00', '06:00:00');
check('ночная смена: пн 23:00 в смене', AssignmentService::isOnSchedule($N, 'shift', d('2026-11-16 23:00:00')));
check('ночная смена: вт 03:00 в смене (продолжение)', AssignmentService::isOnSchedule($N, 'shift', d('2026-11-17 03:00:00')));
check('ночная смена: пн 03:00 вне смены', !AssignmentService::isOnSchedule($N, 'shift', d('2026-11-16 03:00:00')));
$R = $mkEmp('Круглосуточный', $active, $round, false);
check('круглосуточный график: всегда в смене', AssignmentService::isOnSchedule($R, 'round_clock', $sat10));

check('нагрузка без заявок = 0', AssignmentService::load($A) === 0);
$mkReq($A, 'emergency', 'in_progress');
$mkReq($A, 'urgent', 'assigned');
$mkReq($A, 'planned', 'done');
$mkReq($A, 'emergency', 'closed');
check('нагрузка = вес открытых (3 + 2), выполненные и закрытые не считаются', AssignmentService::load($A) === 5, (string)AssignmentService::load($A));

$ctx = fn(string $cat) => ['category_id' => (int)Db::val('SELECT id FROM request_categories WHERE code = ?', [$cat]), 'service_id' => $svc, 'house_id' => $house];
// A: нагрузка 5, B: выходной, значит остаётся A
$p = AssignmentService::pick($ctx('planned'), $mon10);
check('единственный в смене кандидат выбран (B в выходной)', $p && $p['id'] === $A, json_encode($p));
Db::exec('DELETE FROM schedule_exceptions WHERE employee_id = ? AND exception_date = ?', [$B, '2026-11-16']);
$p = AssignmentService::pick($ctx('planned'), $mon10);
check('выбирается минимальная нагрузка (B=0 < A=5)', $p && $p['id'] === $B, json_encode($p));
$mkReq($B, 'emergency', 'assigned');
$mkReq($B, 'urgent', 'in_progress');   // B: 5, как у A
Db::insert('request_assignments', ['request_id' => $mkReq($A, 'planned', 'closed'), 'employee_id' => $A, 'mode' => 'auto', 'assigned_at' => '2026-11-10 10:00:00']);
Db::insert('request_assignments', ['request_id' => $mkReq($B, 'planned', 'closed'), 'employee_id' => $B, 'mode' => 'auto', 'assigned_at' => '2026-11-11 10:00:00']);
$p = AssignmentService::pick($ctx('planned'), $mon10);
check('при равной нагрузке (5 = 5) берётся тот, кому назначали раньше всех (A)', $p && $p['id'] === $A, json_encode($p));
// предел нагрузки
Db::insert('assignment_rules', ['name' => 'Тест: предел 5', 'request_category_id' => null, 'service_category_id' => $svcCat, 'section_id' => null, 'priority' => 0, 'max_load' => 5, 'allow_off_schedule' => 0, 'is_active' => 1]);
$p = AssignmentService::pick($ctx('planned'), $mon10);
check('правило с priority 0: A и B на пределе 5 (5 + вес 1 > 5), кандидатов нет', $p === null, json_encode($p));
Db::insert('assignment_rules', ['name' => 'Тест: предел 9', 'request_category_id' => null, 'service_category_id' => $svcCat, 'section_id' => null, 'priority' => 0, 'max_load' => 9, 'allow_off_schedule' => 0, 'is_active' => 1]);
$rule = AssignmentService::rule($ctx('planned')['category_id'], $svc, $house);
check('применяется правило с наименьшим priority (при равенстве - с меньшим id)', $rule['max_load'] === 5, json_encode($rule));
Db::exec('DELETE FROM assignment_rules WHERE name LIKE ?', ['Тест:%']);
$rule = AssignmentService::rule($ctx('planned')['category_id'], $svc, $house);
check('без правил применяется settings.default_max_load', $rule['max_load'] === (int)Db::val("SELECT `value` FROM settings WHERE `key` = 'default_max_load'") && $rule['rule_id'] === null);

// вне графика: суббота, у A и B нет смены
Db::insert('employee_services', ['employee_id' => $R, 'service_id' => $svc]);
Db::insert('employee_houses', ['employee_id' => $R, 'house_id' => $house]);
$p = AssignmentService::pick($ctx('planned'), $sat10);
check('плановая в субботу: назначается круглосуточному сотруднику (он в смене)', $p && $p['id'] === $R, json_encode($p));
Db::exec('DELETE FROM employee_services WHERE employee_id = ?', [$R]);
$p = AssignmentService::pick($ctx('planned'), $sat10);
check('плановая в субботу: вне графика не назначается (очередь)', $p === null, json_encode($p));
$p = AssignmentService::pick($ctx('emergency'), $sat10);
check('аварийная в субботу: допускается вне графика, off_schedule = true', $p && $p['off_schedule'] === true && in_array($p['id'], [$A, $B], true), json_encode($p));
// статус сотрудника
Db::exec('UPDATE employees SET status_id = ? WHERE id IN (?, ?)', [$vac, $A, $B]);
$p = AssignmentService::pick($ctx('emergency'), $sat10);
check('сотрудник в отпуске не назначается', $p === null, json_encode($p));
Db::exec('UPDATE employees SET status_id = ? WHERE id IN (?, ?)', [$active, $A, $B]);
// специализация и дом
Db::exec('DELETE FROM employee_services WHERE employee_id = ?', [$B]);
$cands = AssignmentService::candidates($ctx('emergency'), $mon10);
$byId = array_column($cands, null, 'id');
check('без нужной услуги сотрудник не подходит (matches=false), но виден в списке', isset($byId[$B]) && $byId[$B]['matches'] === false);

$pdo->rollBack();
echo "\nПройдено: $ok, провалено: $fail\n";
exit($fail ? 1 : 0);
