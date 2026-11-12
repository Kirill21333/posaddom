# -*- coding: utf-8 -*-
"""Приёмочные тесты API ИС «ПосадДом» (ТК-01 ... ТК-18, курсовая, раздел 15.2).

Запуск (сервер на 127.0.0.1:8300, БД установлена с --demo):
    python -X utf8 tests/api_test.py

Тесты создают собственные фикстуры (дом, услуги, исполнители с круглосуточным графиком), поэтому от
времени суток и демо-данных не зависят. Проверки, относящиеся к справочникам, отчётам и администрированию
(API другого модуля), помечаются SKIP, если эндпоинт не отвечает.
"""
import http.cookiejar
import json
import os
import re
import shlex
import struct
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib
from datetime import datetime, timedelta, timezone

BASE = os.environ.get('PD_BASE', 'http://127.0.0.1:8300')
PASS = 'Demo12345'
APP = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MYSQL = shlex.split(os.environ.get('PD_MYSQL', r'C:/xampp/mysql/bin/mysql.exe -u root'))
DBNAME = os.environ.get('PD_DB', 'posaddom')
PHP = os.environ.get('PD_PHP', r'C:\xampp\php\php.exe')
MSK = timezone(timedelta(hours=3))

passed = 0
skipped = 0
fails = []


def now():
    return datetime.now(MSK).replace(tzinfo=None, microsecond=0)


def parse(s):
    return datetime.strptime(s, '%Y-%m-%d %H:%M:%S')


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def call(self, method, path, body=None, raw=False, data=None, headers=None):
        h = dict(headers or {})
        if body is not None:
            data = json.dumps(body).encode()
            h['Content-Type'] = 'application/json'
        req = urllib.request.Request(BASE + urllib.parse.quote(path, safe='/?&=:+%'), data=data, method=method, headers=h)
        try:
            r = self.op.open(req)
            payload, code, ctype = r.read(), r.status, r.headers.get('Content-Type', '')
        except urllib.error.HTTPError as e:
            payload, code, ctype = e.read(), e.code, e.headers.get('Content-Type', '')
        if raw:
            return code, payload, ctype
        try:
            return code, json.loads(payload)
        except Exception:
            return code, payload.decode('utf-8', 'replace')

    def login(self, login, password=PASS):
        return self.call('POST', '/api/auth/login', {'login': login, 'password': password})

    def upload(self, path, filename, content, mime, stage='initial'):
        b = uuid.uuid4().hex
        parts = [f'--{b}\r\nContent-Disposition: form-data; name="stage"\r\n\r\n{stage}\r\n'.encode(),
                 f'--{b}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode(),
                 content, f'\r\n--{b}--\r\n'.encode()]
        return self.call('POST', path, data=b''.join(parts), headers={'Content-Type': f'multipart/form-data; boundary={b}'})


def _mysql(q):
    # запрос передаётся через stdin в UTF-8: аргументы командной строки Windows портят кириллицу
    return subprocess.run([*MYSQL, DBNAME, '-N', '-B', '--default-character-set=utf8mb4'], input="SET time_zone = '+03:00'; " + q,
                          capture_output=True, text=True, encoding='utf-8')


def sql(q):
    r = _mysql(q)
    if r.returncode:
        raise RuntimeError(r.stderr.strip())
    return r.stdout.strip()


def sql_try(q):
    r = _mysql(q)
    return r.returncode, (r.stderr or r.stdout).strip()


def q1(s):
    return "'" + str(s).replace("\\", "\\\\").replace("'", "''") + "'"


def ins(table, **kw):
    cols = ', '.join(f'`{k}`' for k in kw)
    vals = ', '.join('NULL' if v is None else (str(v) if isinstance(v, (int, float)) else (v[1:] if isinstance(v, str) and v.startswith('=') else q1(v))) for v in kw.values())
    return int(sql(f'INSERT INTO `{table}` ({cols}) VALUES ({vals}); SELECT LAST_INSERT_ID();').splitlines()[-1])


def cron(job):
    r = subprocess.run([PHP, os.path.join(APP, 'cron', f'{job}.php')], capture_output=True, text=True, encoding='utf-8', cwd=APP)
    return r.stdout.strip() + r.stderr.strip()


def check(name, cond, info=''):
    global passed
    if cond:
        passed += 1
        print('  ok  ', name)
    else:
        fails.append(name)
        print('  FAIL', name, info if info else '')


def skip(name, why='эндпоинт не отвечает'):
    global skipped
    skipped += 1
    print('  SKIP', name, f'({why})')


def png_bytes(w=8, h=8):
    raw = b''.join(b'\x00' + b'\xd9\xd9\xd9' * w for _ in range(h))
    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')


def jpeg_sample():
    for root, _, files in os.walk(os.path.join(APP, 'storage', 'photos')):
        for f in files:
            if f.lower().endswith('.jpg'):
                with open(os.path.join(root, f), 'rb') as fh:
                    return fh.read()
    return None


def as_user(login):
    c = Client()
    code, d = c.login(login)
    assert code == 200, (login, code, d)
    return c


def run():
    global passed
    stamp = datetime.now().strftime('%H%M%S')
    disp = as_user('morozova.ev')
    admin = as_user('gromov.ks')
    mgr = as_user('vasilyev.op')

    # ---------------------------------------------------------------- фикстуры
    pwhash_src = "SELECT password_hash FROM users WHERE login = 'morozova.ev'"
    role_emp = int(sql("SELECT id FROM roles WHERE code = 'employee'"))
    pos = int(sql("SELECT id FROM positions WHERE name = 'Слесарь-сантехник'"))
    st_active = int(sql("SELECT id FROM employee_statuses WHERE code = 'active'"))
    st_vac = int(sql("SELECT id FROM employee_statuses WHERE code = 'vacation'"))
    sch_round = int(sql("SELECT id FROM schedule_types WHERE code = 'round_clock'"))
    sch_five = int(sql("SELECT id FROM schedule_types WHERE code = 'five_day'"))
    cat_san = int(sql("SELECT id FROM service_categories WHERE name = 'Сантехнические работы'"))
    stat_owner = int(sql("SELECT id FROM client_statuses WHERE code = 'owner'"))
    ts = now().strftime('%Y-%m-%d %H:%M:%S')

    def mk_house(n):
        return ins('houses', section_id=1, address=f'ул. Тестовая {stamp}, {n}', floors=5, entrances=1, premises_count=20, year_built=2000, is_active=1)

    def mk_service(code, paid=False):
        return ins('services', code=f'{code}-{stamp}', category_id=cat_san, name=f'Тестовая услуга {code}', unit='выезд', norm_hours=1, is_paid=1 if paid else 0,
                   price=777 if paid else None, basis='Тест: заявка собственника' if paid else None, norm_document=None if paid else 'Тест: ПП РФ № 290', created_at=ts)

    def mk_emp(name, sched, house, services, with_user=False, status=None):
        e = ins('employees', last_name=name, first_name='Тестер', position_id=pos, status_id=status or st_active, schedule_type_id=sched,
                phone='+7 (900) 111-22-33', email=f'{name.lower()}@example.com', hire_date='2020-01-01', created_at=ts)
        for s in services:
            sql(f'INSERT INTO employee_services VALUES ({e}, {s})')
        sql(f'INSERT INTO employee_houses VALUES ({e}, {house})')
        if with_user:
            sql(f"INSERT INTO users (login, password_hash, role_id, employee_id, created_at) SELECT 'test.{name.lower()}{stamp}', password_hash, {role_emp}, {e}, '{ts}' FROM users WHERE login = 'morozova.ev'")
        return e

    def mk_client(phone, flat_house=None, flat=None, consent=0):
        c = ins('clients', last_name='Тестов', first_name='Клиент', middle_name='Фикстурович', phone=phone, email=None, status_id=stat_owner,
                notify_consent=consent, pd_consent_at=ts, created_at=ts)
        if flat_house:
            p = ins('premises', house_id=flat_house, number=str(flat), type='residential', area=40)
            sql(f'INSERT INTO client_premises (client_id, premise_id) VALUES ({c}, {p})')
            return c, p
        return c, None

    house = mk_house(1)
    svc = mk_service('ТСТ1')
    svc_paid = mk_service('ТСТ2', paid=True)
    e1 = mk_emp('Тестерова', sch_round, house, [svc, svc_paid], with_user=True)
    e2 = mk_emp('Тестеров', sch_round, house, [svc, svc_paid])
    client, premise = mk_client('+7 (999) 000-%s' % stamp[-4:].rjust(4, '0'), house, 5, consent=1)
    # дом и услуга, на которые есть только сотрудник без рабочего графика (всегда вне смены)
    house_off = mk_house(2)
    svc_off = mk_service('ТСТ3')
    e3 = mk_emp('Безграфиков', sch_five, house_off, [svc_off])
    # дом без исполнителей (очередь)
    house_none = mk_house(3)
    # дом для проверки балансировки: два круглосуточных исполнителя
    house_bal = mk_house(4)
    svc_bal = mk_service('ТСТ4')
    b1 = mk_emp('Балансов', sch_round, house_bal, [svc_bal])
    b2 = mk_emp('Балансова', sch_round, house_bal, [svc_bal])

    def new_req(cat='emergency', h=None, s=None, cl=None, user=None, **kw):
        body = {'client_id': cl or client, 'house_id': h or house, 'category_code': cat, 'service_id': s or svc,
                'channel_code': 'phone', 'description': 'Тест: ' + cat, **({'premise_id': premise} if h in (None, house) else {}), **kw}
        return (user or disp).call('POST', '/api/requests', body)

    def req_for(cat='urgent', emp=None, **kw):
        # заявка с гарантированным исполнителем (нагрузка круглосуточных тестовых сотрудников к этому моменту может быть на пределе)
        code, d = new_req(cat, **kw)
        rid = d['data']['id']
        want = emp or e1
        if not d['data']['assignee'] or d['data']['assignee']['id'] != want:
            code2, d2 = disp.call('POST', f'/api/requests/{rid}/assign', {'employee_id': want})
            assert code2 == 200, d2
        return rid

    print('== ТК-01. Вход, выход и блокировка учётной записи')
    c = Client()
    code, d = c.call('GET', '/api/auth/me')
    check('me без входа: 401', code == 401 and d['ok'] is False, d)
    code, d = c.login('morozova.ev', 'неверный')
    check('неверный пароль: 401 и сообщение на русском', code == 401 and 'Неверный' in d['error'], d)
    code, d = c.login('morozova.ev')
    check('вход диспетчера: 200, роль dispatcher', code == 200 and d['data']['user']['role'] == 'dispatcher' and d['data']['user']['employee_id'], d)
    code, d = c.call('GET', '/api/auth/me')
    check('me после входа', code == 200 and d['data']['user']['login'] == 'morozova.ev', d)
    check('в ответе нет хеша пароля', 'password_hash' not in json.dumps(d))
    code, d = c.call('POST', '/api/auth/logout')
    check('выход', code == 200)
    code, d = c.call('GET', '/api/auth/me')
    check('после выхода me: 401', code == 401)
    lock_login = f'locktest{stamp}'
    sql(f"INSERT INTO users (login, password_hash, role_id, employee_id, created_at) SELECT '{lock_login}', password_hash, {role_emp}, NULL, '{ts}' FROM users WHERE login = 'morozova.ev'")
    c = Client()
    codes = [c.login(lock_login, 'bad')[0] for _ in range(5)]
    check('4 неверные попытки 401, пятая блокирует (403)', codes[:4] == [401] * 4 and codes[4] == 403, codes)
    code, d = c.login(lock_login)
    check('верный пароль при блокировке: 403 «заблокирована»', code == 403 and 'заблокирована' in d['error'], d)
    locked = sql(f"SELECT TIMESTAMPDIFF(MINUTE, NOW(), locked_until) FROM users WHERE login = '{lock_login}'")
    check('блокировка на 15 минут', locked in ('14', '15'), locked)
    sql(f"UPDATE users SET locked_until = NOW() - INTERVAL 1 MINUTE WHERE login = '{lock_login}'")
    code, d = c.login(lock_login)
    check('после окончания блокировки вход возможен', code == 200, d)
    check('журнал аудита: вход и блокировка', int(sql("SELECT COUNT(*) FROM audit_log WHERE action IN ('auth.login', 'auth.locked')")) >= 2)
    code, d = c.call('POST', '/api/auth/password', {'old': PASS, 'new': 'x'})
    check('смена пароля: короткий пароль 422', code == 422, d)
    code, d = c.call('POST', '/api/auth/password', {'old': PASS, 'new': 'Newpass123'})
    check('смена пароля 200', code == 200, d)
    check('новый пароль работает', Client().login(lock_login, 'Newpass123')[0] == 200)

    print('== ТК-02. Регистрация аварийной заявки: номер и сроки')
    t0 = now()
    code, d = new_req('emergency')
    check('создание аварийной заявки: 200', code == 200, d)
    r1 = d['data']
    check('номер вида А-ГГГГ-NNNNNN', re.fullmatch(r'А-%d-\d{6}' % t0.year, r1['number']) is not None, r1['number'])
    arr = parse(r1['arrive_due'])
    due = parse(r1['due_at'])
    check('срок выезда +30 минут', abs((arr - t0).total_seconds() - 1800) <= 5, r1['arrive_due'])
    check('срок устранения +24 часа', abs((due - t0).total_seconds() - 86400) <= 5, r1['due_at'])
    check('автоназначение: назначен исполнитель, не в очереди', r1['assignee'] and r1['assignee']['id'] in (e1, e2) and r1['queued'] is False, r1)
    code, d = disp.call('GET', f"/api/requests/{r1['id']}")
    card = d['data']
    check('карточка: статус «Назначен исполнитель»', card['status']['code'] == 'assigned')
    hist = [h['status'] for h in card['history']]
    check('история: new -> accepted -> assigned', hist == ['new', 'accepted', 'assigned'], hist)
    check('история: автор new/accepted - диспетчер, назначение - система', card['history'][0]['user_name'].startswith('Морозова') and card['history'][2]['user_name'] == 'система', card['history'])
    check('предупреждение на 75 % срока', abs((parse(card['deadlines']['warn_at']) - t0).total_seconds() - 0.75 * 86400) <= 5)
    check('карточка: клиент, услуга, помещение, allowed_actions', card['client']['id'] == client and card['service']['code'].startswith('ТСТ1') and card['premise']['number'] == '5'
          and 'assign' in card['allowed_actions'] and 'edit' in card['allowed_actions'], card['allowed_actions'])
    check('аварийная заявка поставлена в очередь передачи в РСО', int(sql(f"SELECT COUNT(*) FROM integration_queue WHERE request_id = {r1['id']} AND `system` = 'rso'")) == 1)
    check('клиент с согласием: SMS в storage/sms и очередь sms',
          int(sql(f"SELECT COUNT(*) FROM integration_queue WHERE request_id = {r1['id']} AND `system` = 'sms' AND status = 'sent'")) == 2)  # принята + назначен исполнитель
    check('исполнителю создано уведомление на сайте', int(sql(f"SELECT COUNT(*) FROM notifications WHERE request_id = {r1['id']} AND channel = 'site' AND type = 'new_request'")) == 1)
    check('аудит: создание заявки', int(sql(f"SELECT COUNT(*) FROM audit_log WHERE action = 'request.create' AND entity_id = {r1['id']}")) == 1)
    code, d = disp.call('POST', '/api/requests', {'client_id': client, 'house_id': house, 'category_code': 'emergency', 'service_id': svc, 'description': ''})
    check('пустое описание: 422', code == 422 and d['fields'].get('description'), d)
    code, d = disp.call('POST', '/api/requests', {'client_id': client, 'house_id': house, 'category_code': 'bad', 'service_id': svc, 'description': 'x'})
    check('неизвестная категория: 422', code == 422, d)
    other_house_premise = ins('premises', house_id=house_none, number='7', type='residential', area=30)
    code, d = new_req('planned', premise_id=other_house_premise)
    check('помещение другого дома: 422', code == 422, d)

    print('== ТК-03. Сквозная нумерация')
    nums = [r1['number']]
    for cat in ('urgent', 'planned', 'emergency'):
        code, d = new_req(cat)
        nums.append(d['data']['number'])
    seq = [int(n[-6:]) for n in nums]
    check('номера идут подряд независимо от категории', seq == list(range(seq[0], seq[0] + 4)), nums)
    check('префиксы А, С, П, А', [n[0] for n in nums] == ['А', 'С', 'П', 'А'], nums)
    check('номера уникальны', len(set(nums)) == 4)
    code, d = disp.call('POST', '/api/requests/preview', {'category_code': 'planned', 'house_id': house, 'service_id': svc})
    check('preview: следующий свободный номер без побочных эффектов',
          d['data']['number'] == f'П-{t0.year}-{seq[-1] + 1:06d}' and disp.call('POST', '/api/requests/preview', {'category_code': 'planned'})[1]['data']['number'] == d['data']['number'], d)
    cnt = sql(f"SELECT last_value FROM request_number_seq WHERE `year` = {t0.year}")
    check('счётчик года в БД совпадает с последним выданным', int(cnt) == seq[-1], cnt)

    print('== ТК-04. Сроки плановой заявки (пятница 16:00)')
    today = now().date()
    fri = today - timedelta(days=(today.weekday() - 4) % 7)
    if fri == today:
        fri -= timedelta(days=7)
    fri_dt = datetime(fri.year, fri.month, fri.day, 16, 0, 0)
    code, d = disp.call('POST', '/api/requests/preview', {'category_code': 'planned', 'house_id': house, 'service_id': svc, 'received_at': fri_dt.strftime('%Y-%m-%d %H:%M:%S')})
    mon = fri_dt + timedelta(days=3)
    check('выезд: в понедельник в то же время (16:00)', code == 200 and d['data']['arrive_due'] == mon.strftime('%Y-%m-%d %H:%M:%S'), d)
    check('устранение: +168 часов', d['data']['due_at'] == (fri_dt + timedelta(hours=168)).strftime('%Y-%m-%d %H:%M:%S'), d)
    check('предупреждение на 75 % срока (126 часов)', d['data']['warn_at'] == (fri_dt + timedelta(hours=126)).strftime('%Y-%m-%d %H:%M:%S'), d)
    code, d = disp.call('POST', '/api/requests/preview', {'category_code': 'planned', 'received_at': (now() + timedelta(days=1)).strftime('%Y-%m-%d %H:%M:%S')})
    check('дата поступления в будущем: 422', code == 422, d)
    code, d = disp.call('POST', '/api/requests/preview', {'category_code': 'urgent', 'received_at': '2026-03-02 10:00:00'})
    check('дата старше 30 суток: 422', code == 422, d)

    print('== ТК-05. Дубль клиента (телефон + помещение)')
    code, d = disp.call('POST', '/api/clients', {'last_name': 'Дублёв', 'first_name': 'Дубль', 'phone': sql(f"SELECT phone FROM clients WHERE id = {client}"),
                                                'status_code': 'owner', 'status_id': stat_owner, 'premise': {'house_id': house, 'number': '5'}})
    if code in (404, 405):
        skip('ТК-05 дубль клиента (модуль клиентов)')
    else:
        check('дубль: 409 и {duplicate}', code == 409 and 'duplicate' in d, d)
        code, d = disp.call('POST', '/api/clients', {'last_name': 'Дублёв', 'first_name': 'Дубль', 'phone': sql(f"SELECT phone FROM clients WHERE id = {client}"),
                                                    'status_code': 'owner', 'status_id': stat_owner, 'premise': {'house_id': house, 'number': '5'}, 'confirm_duplicate': True})
        check('с confirm_duplicate: клиент создан', code in (200, 201), d)
        check('решение записано в журнал аудита', int(sql("SELECT COUNT(*) FROM audit_log WHERE action LIKE '%duplicate%' OR new_value LIKE '%duplicate%'")) >= 1)

    print('== ТК-06. Автоназначение по минимальной нагрузке')
    assignees = []
    for cat in ('emergency', 'emergency', 'planned', 'urgent'):
        code, d = new_req(cat, h=house_bal, s=svc_bal)
        assignees.append(d['data']['assignee']['id'] if d['data']['assignee'] else None)
    check('1-я аварийная: первому (нагрузка 0 у обоих)', assignees[0] == b1, assignees)
    check('2-я аварийная: второму (у первого 3, у второго 0)', assignees[1] == b2, assignees)
    check('3-я плановая: при равенстве - тому, кому назначали раньше (первый)', assignees[2] == b1, assignees)
    check('4-я срочная: у первого 4, у второго 3 -> второй', assignees[3] == b2, assignees)
    code, d = disp.call('GET', f'/api/requests/{r1["id"]}/candidates')
    cand = {c['id']: c for c in d['data']} if code == 200 else {}
    check('список кандидатов с нагрузкой и пределом', code == 200 and e1 in cand and cand[e1]['max_load'] >= 1 and 'on_schedule' in cand[e1], d)
    check('нагрузка = сумма весов открытых заявок (1: А3 + П1 = 4; 2: А3 + С2 = 5)',
          int(sql(f'SELECT load_value FROM v_employee_load WHERE employee_id = {b1}')) == 4 and int(sql(f'SELECT load_value FROM v_employee_load WHERE employee_id = {b2}')) == 5)
    sql('UPDATE assignment_rules SET is_active = 0')
    sql(f"INSERT INTO assignment_rules (name, request_category_id, service_category_id, section_id, priority, max_load, allow_off_schedule, is_active) VALUES ('Тест предел', NULL, NULL, NULL, 0, 6, 0, 1)")
    code, d = new_req('emergency', h=house_bal, s=svc_bal)
    check('предел max_load из правила: при нагрузке 4 и 5 аварийная (вес 3) не назначается, заявка в очереди', d['data']['assignee'] is None and d['data']['queued'] is True, d)
    sql('DELETE FROM assignment_rules WHERE name = \'Тест предел\'')
    sql('UPDATE assignment_rules SET is_active = 1')
    queued_bal = d['data']['id']

    print('== ТК-07. Вне графика (аварийная) и очередь (плановая)')
    code, d = new_req('emergency', h=house_off, s=svc_off)
    check('аварийная: назначена сотруднику вне графика', code == 200 and d['data']['assignee'] and d['data']['assignee']['id'] == e3 and d['data']['queued'] is False, d)
    rid_off = d['data']['id']
    check('в назначении off_schedule = 1', sql(f'SELECT off_schedule FROM request_assignments WHERE request_id = {rid_off}') == '1')
    check('уведомление «вызов вне графика»', int(sql(f"SELECT COUNT(*) FROM notifications WHERE request_id = {rid_off} AND type = 'off_schedule' AND channel = 'site' AND employee_id = {e3}")) == 1)
    code, d = new_req('planned', h=house_off, s=svc_off)
    check('плановая: вне графика не назначается, заявка в очереди', code == 200 and d['data']['assignee'] is None and d['data']['queued'] is True, d)
    qid = d['data']['id']
    code, d = disp.call('GET', f'/api/requests/{qid}')
    check('в карточке: manual_assign_required, исполнителя нет', d['data']['manual_assign_required'] is True and d['data']['assignee'] is None and d['data']['status']['code'] == 'accepted')
    code, d = disp.call('GET', '/api/requests?queue=1')
    check('очередь нераспределённых содержит заявку', code == 200 and qid in [i['id'] for i in d['data']['items']] and d['data']['counts']['queue'] >= 1)
    check('диспетчерам отправлено уведомление об очереди', int(sql(f"SELECT COUNT(*) FROM notifications WHERE request_id = {qid} AND type = 'queued'")) >= 1)
    code, d = new_req('urgent', h=house_none, s=svc)
    check('нет привязанных исполнителей: очередь', d['data']['queued'] is True and d['data']['assignee'] is None, d)
    code, d = disp.call('POST', '/api/requests/preview', {'category_code': 'emergency', 'house_id': house_off, 'service_id': svc_off})
    check('preview показывает исполнителя вне графика', d['data']['assignee'] and d['data']['assignee']['off_schedule'] is True, d)

    print('== ТК-08. Ручное назначение и переназначение')
    code, d = disp.call('POST', f'/api/requests/{qid}/assign', {'employee_id': e1, 'reason': 'Ручное назначение диспетчером'})
    check('ручное назначение из очереди: 200', code == 200 and d['data']['assignee']['id'] == e1 and d['data']['status']['code'] == 'assigned', d)
    check('запись request_assignments (mode manual), очередь снята', sql(f'SELECT mode FROM request_assignments WHERE request_id = {qid} AND unassigned_at IS NULL') == 'manual'
          and sql(f'SELECT manual_assign_required FROM requests WHERE id = {qid}') == '0')
    code, d = disp.call('POST', f'/api/requests/{qid}/assign', {'employee_id': e2, 'reason': 'Перегрузка исполнителя'})
    check('переназначение: исполнитель сменился, 2 записи назначений', code == 200 and d['data']['assignee']['id'] == e2 and len(d['data']['assignments']) == 2, d)
    check('старое назначение закрыто (unassigned_at)', int(sql(f'SELECT COUNT(*) FROM request_assignments WHERE request_id = {qid} AND unassigned_at IS NOT NULL')) == 1)
    check('прежнему исполнителю ушло уведомление о переназначении', int(sql(f"SELECT COUNT(*) FROM notifications WHERE request_id = {qid} AND type = 'reassigned' AND channel = 'site' AND employee_id = {e1}")) == 1)
    code, d = disp.call('POST', f'/api/requests/{qid}/assign', {'employee_id': e2})
    check('назначение того же исполнителя: 422', code == 422, d)
    vac = sql("SELECT id FROM employees WHERE last_name = 'Иванова' AND first_name = 'Марина'")
    code, d = disp.call('POST', f'/api/requests/{qid}/assign', {'employee_id': int(vac)})
    check('сотрудник в отпуске: назначение отклонено (422)', code == 422 and 'Активен' in d['error'], d)
    code, d = disp.call('POST', f'/api/requests/{qid}/assign', {'employee_id': 999999})
    check('несуществующий сотрудник: 404', code == 404, d)
    code, d = disp.call('POST', f'/api/requests/{queued_bal}/assign', {'employee_id': None})
    check('автоназначение из очереди (без указания сотрудника): 200, назначен подходящий исполнитель',
          code == 200 and d['data']['assignee']['id'] in (b1, b2) and d['data']['manual_assign_required'] is False, d)

    print('== ТК-09. История статусов неизменяема (триггеры БД)')
    rc, msg = sql_try(f"UPDATE request_status_history SET comment = 'взлом' WHERE request_id = {r1['id']}")
    check('UPDATE истории отклонён триггером', rc != 0 and 'не подлежит изменению' in msg, msg)
    rc, msg = sql_try(f"DELETE FROM request_status_history WHERE request_id = {r1['id']}")
    check('DELETE истории отклонён триггером', rc != 0 and 'не подлежит удалению' in msg, msg)
    rc, msg = sql_try(f"UPDATE requests SET premise_id = {other_house_premise} WHERE id = {r1['id']}")
    check('триггер: помещение должно принадлежать дому заявки', rc != 0 and 'не принадлежит' in msg, msg)

    print('== ТК-10. Фотографии')
    emp1 = as_user_by_sql(stamp, 'тестерова')
    rid = req_for('urgent')
    worker = emp1
    png = png_bytes()
    code, d = disp.upload(f'/api/requests/{rid}/photos', 'до ремонта.png', png, 'image/png', 'initial')
    check('PNG «до»: загружен', code == 200 and d['data']['stage'] == 'initial' and d['data']['url'].startswith('/api/photos/'), d)
    ph = d['data']
    jp = jpeg_sample()
    if jp:
        code, d = disp.upload(f'/api/requests/{rid}/photos', 'photo.jpg', jp, 'image/jpeg', 'initial')
        check('JPEG: загружен', code == 200, d)
    else:
        skip('JPEG-образец (нет демо-фото с GD)', 'в storage/photos нет .jpg')
    code, d = disp.upload(f'/api/requests/{rid}/photos', 'virus.jpg', b'MZ\x90\x00' + b'\0' * 200, 'image/jpeg')
    check('исполняемый файл под видом JPEG отклонён (422)', code == 422, d)
    code, d = disp.upload(f'/api/requests/{rid}/photos', 'note.png', b'just text, not an image at all', 'image/png')
    check('текст под видом PNG отклонён (422)', code == 422, d)
    code, d = disp.upload(f'/api/requests/{rid}/photos', 'big.jpg', (jp or png) + b'\0' * (10 * 1024 * 1024 + 1024), 'image/jpeg')
    check('файл больше 10 МБ отклонён (422)', code == 422 and 'МБ' in d['error'], d)
    code, d = disp.upload(f'/api/requests/{rid}/photos', 'a.png', png, 'image/png', 'bad')
    check('неверный этап фото: 422', code == 422, d)
    code, body, ctype = disp.call('GET', ph['url'], raw=True)
    check('выдача фото: изображение с проверкой прав', code == 200 and ctype.startswith('image/png') and body == png, (code, ctype))
    code, body, ctype = worker.call('GET', ph['url'], raw=True)
    check('исполнитель этой заявки видит фото', code == 200)
    code, body, ctype = as_user('smirnov.av').call('GET', ph['url'], raw=True)
    check('чужой исполнитель фото не видит (403)', code == 403, code)
    code, body, ctype = Client().call('GET', ph['url'], raw=True)
    check('аноним фото не видит (401)', code == 401, code)
    row = sql(f"SELECT file_path, DATEDIFF(retention_until, DATE(uploaded_at)), uploaded_by FROM request_photos WHERE id = {ph['id']}").split('\t')
    check('путь storage/photos/<год>/<заявка>/...', row[0].startswith(f'{now().year}/{rid}/') and os.path.isfile(os.path.join(APP, 'storage', 'photos', row[0])), row)
    check('retention_until = дата загрузки + 3 года', 1094 <= int(row[1]) <= 1096, row)
    code, d = admin.call('DELETE', f"/api/photos/{ph['id']}")
    check('удаление фото раньше срока хранения: 409', code == 409, d)
    rc, msg = sql_try(f"DELETE FROM request_photos WHERE id = {ph['id']}")
    check('триггер БД: удаление раньше срока запрещено', rc != 0 and 'срока хранения' in msg, msg)
    code, d = worker.upload(f'/api/requests/{qid}/photos', 'a.png', png, 'image/png')
    check('исполнитель не может загружать фото в чужую заявку (403)', code == 403, d)

    print('== ТК-11. Предупреждение о приближении срока')
    rid_w = req_for('urgent')
    sql(f"UPDATE requests SET warn_at = NOW() - INTERVAL 1 MINUTE, warning_sent = 0 WHERE id = {rid_w}")
    before = int(sql(f"SELECT COUNT(*) FROM notifications WHERE request_id = {rid_w} AND type = 'deadline_warning'"))
    out = cron('check_deadlines')
    check('cron check_deadlines отработал', 'check_deadlines' in out and 'Ошибка' not in out, out)
    n_all = int(sql(f"SELECT COUNT(*) FROM notifications WHERE request_id = {rid_w} AND type = 'deadline_warning' AND channel = 'site'"))
    check('уведомления ответственному и диспетчерам', n_all >= 2 and n_all > before, n_all)
    check('warning_sent = 1', sql(f'SELECT warning_sent FROM requests WHERE id = {rid_w}') == '1')
    cron('check_deadlines')
    check('повторный запуск не дублирует предупреждения', int(sql(f"SELECT COUNT(*) FROM notifications WHERE request_id = {rid_w} AND type = 'deadline_warning' AND channel = 'site'")) == n_all)

    print('== ТК-12. Просрочка')
    rid_o = req_for('emergency')
    sql(f"UPDATE requests SET received_at = NOW() - INTERVAL 30 HOUR, arrive_due = NOW() - INTERVAL 29 HOUR - INTERVAL 30 MINUTE, due_at = NOW() - INTERVAL 90 MINUTE, warn_at = NOW() - INTERVAL 7 HOUR, is_overdue = 0, warning_sent = 1 WHERE id = {rid_o}")
    cron('check_deadlines')
    check('is_overdue = 1 после cron', sql(f'SELECT is_overdue FROM requests WHERE id = {rid_o}') == '1')
    check('руководителю отправлено уведомление о просрочке', int(sql(f"SELECT COUNT(*) FROM notifications n JOIN users u ON u.employee_id = n.employee_id JOIN roles r ON r.id = u.role_id WHERE n.request_id = {rid_o} AND n.type = 'overdue' AND r.code = 'manager'")) >= 1)
    code, d = disp.call('GET', f'/api/requests/{rid_o}')
    check('в карточке: просрочка 90 минут', d['data']['is_overdue'] is True and 89 <= d['data']['overdue_minutes'] <= 92, d['data']['overdue_minutes'])
    code, d = disp.call('GET', '/api/requests?overdue=1&per_page=200')
    ids = [i['id'] for i in d['data']['items']]
    check('список просроченных содержит заявку, счётчик overdue', rid_o in ids and d['data']['counts']['overdue'] >= len(ids), d['data']['counts'])
    code, d = mgr.call('GET', '/api/requests?overdue=1&per_page=200')
    check('руководитель видит просроченные', code == 200 and rid_o in [i['id'] for i in d['data']['items']])
    check('представление v_overdue_requests: величина просрочки', 89 <= int(sql(f'SELECT overdue_minutes FROM v_overdue_requests WHERE request_id = {rid_o}')) <= 92)

    print('== ТК-13. Закрытие заявки, запрет правки закрытой, дополнительное обращение')
    rid_c = req_for('urgent')
    code, d = emp1.call('POST', f'/api/requests/{rid_c}/status', {'status': 'done'})
    check('нельзя перейти assigned -> done (422)', code == 422, d)
    code, d = emp1.call('POST', f'/api/requests/{rid_c}/status', {'status': 'in_progress', 'comment': 'выехал'})
    check('исполнитель: Выполняется (arrived_at проставлен)', code == 200 and d['data']['status']['code'] == 'in_progress' and d['data']['deadlines']['arrived_at'], d)
    code, d = emp1.call('POST', f'/api/requests/{rid_c}/comments', {'text': 'Работы начаты'})
    check('исполнитель добавляет комментарий', code == 200 and d['data']['text'] == 'Работы начаты', d)
    code, d = emp1.call('POST', f'/api/requests/{rid_c}/status', {'status': 'done'})
    check('исполнитель: Выполнена (completed_at)', code == 200 and d['data']['deadlines']['completed_at'], d)
    code, d = emp1.call('POST', f'/api/requests/{rid_c}/status', {'status': 'closed'})
    check('исполнитель не может закрывать (403)', code == 403, d)
    code, d = disp.call('POST', f'/api/requests/{rid_c}/status', {'status': 'closed'})
    check('закрытие без фото «после»: 422', code == 422 and 'фото' in d['error'], d)
    code, d = emp1.upload(f'/api/requests/{rid_c}/photos', 'after.png', png, 'image/png', 'final')
    check('исполнитель загружает фото «после»', code == 200 and d['data']['stage'] == 'final', d)
    code, d = disp.call('POST', f'/api/requests/{rid_c}/status', {'status': 'closed'})
    check('диспетчер закрывает заявку', code == 200 and d['data']['status']['code'] == 'closed' and d['data']['deadlines']['closed_at'], d)
    check('после закрытия allowed_actions: только дополнительный комментарий', d['data']['allowed_actions'] == ['comment_additional'], d['data']['allowed_actions'])
    check('клиенту ушло уведомление о закрытии', int(sql(f"SELECT COUNT(*) FROM notifications WHERE request_id = {rid_c} AND type = 'request_closed' AND recipient_type = 'client'")) >= 1)
    code, d = disp.call('PUT', f'/api/requests/{rid_c}', {'description': 'правка'})
    check('правка закрытой заявки: 409', code == 409, d)
    code, d = disp.call('POST', f'/api/requests/{rid_c}/comments', {'text': 'обычный'})
    check('обычный комментарий к закрытой: 409', code == 409, d)
    code, d = disp.call('POST', f'/api/requests/{rid_c}/comments', {'text': 'Клиент перезвонил', 'is_additional_contact': True})
    check('комментарий с пометкой о дополнительном обращении принят', code == 200 and d['data']['is_additional_contact'] is True, d)
    rc, msg = sql_try(f"UPDATE requests SET description = 'взлом' WHERE id = {rid_c}")
    check('триггер БД: закрытую заявку нельзя изменить', rc != 0 and 'не подлежит редактированию' in msg, msg)
    rc, msg = sql_try(f"UPDATE requests SET is_overdue = 1, updated_at = NOW() WHERE id = {rid_c}")
    check('триггер БД: служебные поля (is_overdue, updated_at) менять можно', rc == 0, msg)
    rc, msg = sql_try(f"INSERT INTO request_comments (request_id, author_id, text, is_additional_contact, created_at) VALUES ({rid_c}, 1, 'x', 0, NOW())")
    check('триггер БД: обычный комментарий к закрытой запрещён', rc != 0 and 'дополнительном обращении' in msg, msg)
    code, d = disp.call('POST', f'/api/requests/{rid_c}/status', {'status': 'cancelled'})
    check('из «Закрыта» перехода нет (422)', code == 422, d)
    code, d = disp.upload(f'/api/requests/{rid_c}/photos', 'late.png', png, 'image/png')
    check('фото к закрытой заявке: 409', code == 409, d)
    code, d = disp.call('GET', f'/api/requests/{rid_c}')
    check('в истории все этапы, включая closed', [h['status'] for h in d['data']['history']] == ['new', 'accepted', 'assigned', 'in_progress', 'done', 'closed'], [h['status'] for h in d['data']['history']])
    check('бесплатная услуга: начисления нет', d['data']['charge'] is None)

    print('== ТК-14. Платная услуга: начисление при закрытии')
    rid_p = req_for('planned', s=svc_paid)
    emp1.call('POST', f'/api/requests/{rid_p}/status', {'status': 'in_progress'})
    emp1.call('POST', f'/api/requests/{rid_p}/status', {'status': 'done'})
    emp1.upload(f'/api/requests/{rid_p}/photos', 'after.png', png, 'image/png', 'final')
    sql(f"UPDATE services SET price = 888 WHERE id = {svc_paid}")
    code, d = disp.call('POST', f'/api/requests/{rid_p}/status', {'status': 'closed'})
    ch = d['data']['charge'] if code == 200 else None
    check('при закрытии создано начисление (сумма из справочника на момент закрытия)', ch and ch['amount'] == 888 and ch['status'] == 'accrued' and ch['basis'].startswith('Тест'), d)
    check('одно начисление в charges', int(sql(f'SELECT COUNT(*) FROM charges WHERE request_id = {rid_p}')) == 1)
    check('v_monthly_charges учитывает начисление', float(sql(f"SELECT COALESCE(SUM(total_amount), 0) FROM v_monthly_charges WHERE service_id = {svc_paid}")) == 888.0)

    print('== ТК-15. Справочник услуг: архив вместо удаления')
    sql(f"UPDATE services SET is_archived = 1 WHERE id = {svc_bal}")
    code, d = new_req('planned', h=house_bal, s=svc_bal)
    check('архивная услуга не выбирается в новой заявке (422)', code == 422 and 'архив' in d['error'], d)
    sql(f"UPDATE services SET is_archived = 0 WHERE id = {svc_bal}")
    rc, msg = sql_try(f'DELETE FROM services WHERE id = {svc}')
    check('БД: услугу с заявками удалить нельзя (внешний ключ)', rc != 0 and 'foreign key' in msg.lower(), msg)
    code, d = admin.call('DELETE', f'/api/services/{svc}')
    if code in (404, 405):
        skip('удаление/архив услуги через API (модуль справочников)')
    else:
        check('DELETE услуги с заявками: 409', code == 409, d)
        code, d = admin.call('POST', f'/api/services/{svc_bal}/archive')
        check('архивирование услуги: 200', code == 200, d)
        code, d = new_req('planned', h=house_bal, s=svc_bal)
        check('архивная услуга: новая заявка 422', code == 422, d)
        admin.call('POST', f'/api/services/{svc_bal}/restore')
        check('изменения пишутся в service_changes', int(sql(f'SELECT COUNT(*) FROM service_changes WHERE service_id = {svc_bal}')) >= 1)

    print('== ТК-16. Разграничение прав')
    smirnov = as_user('smirnov.av')
    emp_id = int(sql("SELECT employee_id FROM users WHERE login = 'smirnov.av'"))
    code, d = smirnov.call('GET', '/api/requests?per_page=200')
    items = d['data']['items'] if code == 200 else []
    check('исполнитель видит в списке только свои заявки', code == 200 and items and all(i['assignee'] and i['assignee']['id'] == emp_id for i in items), len(items))
    check('счётчики исполнителя считаются в его пределах', d['data']['counts']['all'] == d['data']['total'])
    code, d = smirnov.call('GET', f'/api/requests/{r1["id"]}')
    check('чужая заявка: 403', code == 403, d)
    code, d = smirnov.call('GET', f'/api/requests/{items[0]["id"]}') if items else (0, {})
    check('своя заявка: 200 и allowed_actions исполнителя', code == 200 and set(d['data']['allowed_actions']) <= {'status:in_progress', 'status:done', 'comment', 'photo'}, d)
    code, d = smirnov.call('POST', '/api/requests', {'client_id': client, 'house_id': house, 'category_code': 'planned', 'service_id': svc, 'description': 'x'})
    check('исполнитель не создаёт заявки (403)', code == 403, d)
    code, d = smirnov.call('POST', f'/api/requests/{items[0]["id"]}/assign', {'employee_id': e1}) if items else (403, {})
    check('исполнитель не назначает (403)', code == 403, d)
    code, d = smirnov.call('PUT', f'/api/requests/{r1["id"]}', {'description': 'x'})
    check('исполнитель не редактирует (403)', code == 403, d)
    code, d = smirnov.call('POST', f'/api/requests/{r1["id"]}/status', {'status': 'cancelled'})
    check('исполнитель не отменяет чужую заявку (403)', code == 403, d)
    code, d = smirnov.call('GET', f'/api/requests/{r1["id"]}/candidates')
    check('исполнитель не видит кандидатов (403)', code == 403, d)
    code, d = Client().call('GET', '/api/requests')
    check('аноним: 401', code == 401, d)
    code, d = Client().call('POST', '/api/requests', {})
    check('аноним не создаёт заявки: 401', code == 401, d)
    code, d = mgr.call('POST', '/api/requests', {'client_id': client, 'house_id': house, 'category_code': 'planned', 'service_id': svc, 'description': 'x'})
    check('руководитель не создаёт заявки (403)', code == 403, d)
    code, d = mgr.call('GET', '/api/requests?per_page=1')
    check('руководитель видит все заявки', code == 200 and d['data']['counts']['all'] >= 36, d['data']['counts'])
    code, d = mgr.call('PUT', f'/api/requests/{r1["id"]}', {'description': 'Уточнённое описание от руководителя'})
    check('руководитель редактирует заявку до закрытия', code == 200 and d['data']['description'].startswith('Уточнённое'), d)
    check('правка попала в журнал аудита', int(sql(f"SELECT COUNT(*) FROM audit_log WHERE action = 'request.update' AND entity_id = {r1['id']}")) == 1)
    code, d = mgr.call('POST', f'/api/requests/{r1["id"]}/assign', {'employee_id': e2 if r1['assignee']['id'] == e1 else e1, 'reason': 'Балансировка'})
    check('руководитель переназначает', code == 200, d)
    code, d = disp.call('DELETE', f"/api/photos/{ph['id']}")
    check('диспетчер не удаляет фото (403)', code == 403, d)
    code, d = disp.call('PUT', f'/api/requests/{r1["id"]}', {'category_code': 'planned'})
    check('смена категории пересчитывает сроки от времени поступления (плановая: +168 ч)', code == 200 and parse(d['data']['deadlines']['due_at']) - parse(d['data']['received_at']) == timedelta(hours=168), d)
    code, d = disp.call('POST', f'/api/requests/{r1["id"]}/status', {'status': 'rejected'})
    check('отклонить можно только new/accepted (assigned -> rejected: 422)', code == 422, d)
    code, d = disp.call('POST', f'/api/requests/{r1["id"]}/status', {'status': 'cancelled', 'comment': 'Клиент отказался'})
    check('диспетчер отменяет заявку, исполнитель освобождён', code == 200 and d['data']['status']['code'] == 'cancelled' and d['data']['allowed_actions'] == ['comment'], d)
    check('отмена: назначение закрыто', int(sql(f"SELECT COUNT(*) FROM request_assignments WHERE request_id = {r1['id']} AND unassigned_at IS NULL")) == 0)
    code, d = disp.call('GET', '/api/ref')
    ref = d['data'] if code == 200 else {}
    check('GET /ref: все справочники', all(k in ref for k in ('categories', 'statuses', 'channels', 'houses', 'service_categories', 'positions', 'employee_statuses', 'schedule_types', 'client_statuses', 'sections', 'roles')), list(ref))
    check('GET /ref: 3 категории, 8 статусов, 5 каналов', len(ref.get('categories', [])) == 3 and len(ref.get('statuses', [])) == 8 and len(ref.get('channels', [])) == 5)
    code, d = disp.call('GET', '/api/requests?status=new&category=emergency')
    check('фильтры списка: статус + категория', code == 200 and all(i['status']['code'] == 'new' and i['category']['code'] == 'emergency' for i in d['data']['items']) and d['data']['total'] >= 1, d['data']['total'])
    code, d = disp.call('GET', '/api/requests?status=new,accepted&per_page=200')
    check('status=new,accepted: вкладка «Новые» совпадает с counts.new',
          code == 200 and d['data']['total'] == d['data']['counts']['new'] and all(i['status']['code'] in ('new', 'accepted') for i in d['data']['items']), d['data']['counts'])
    code, d = disp.call('GET', '/api/requests?status=assigned,in_progress&per_page=200')
    check('status=assigned,in_progress: вкладка «В работе» совпадает с counts.in_work',
          code == 200 and d['data']['total'] == d['data']['counts']['in_work'] and all(i['status']['code'] in ('assigned', 'in_progress') for i in d['data']['items']), d['data']['counts'])
    code, d = disp.call('GET', '/api/requests?status=closed&per_page=1')
    check('одиночный статус работает как раньше', code == 200 and d['data']['total'] == d['data']['counts']['closed'], d['data']['counts'])
    code, d = disp.call('GET', '/api/requests?status=new,bogus')
    check('неизвестный код статуса: 422 с понятным текстом', code == 422 and 'bogus' in d['error'], d)
    code, d = disp.call('GET', '/api/requests?q=А-2026-000145')
    check('поиск по номеру', code == 200 and d['data']['total'] == 1 and d['data']['items'][0]['number'] == 'А-2026-000145', d['data']['total'])
    code, d = disp.call('GET', '/api/requests?q=Петрова&per_page=5')
    check('поиск по ФИО клиента', code == 200 and d['data']['total'] >= 1)
    code, d = disp.call('GET', f'/api/requests?employee={e1}&sort=received&per_page=3')
    check('фильтр по исполнителю, пагинация', code == 200 and len(d['data']['items']) <= 3 and all(i['assignee']['id'] == e1 for i in d['data']['items']))
    code, d = disp.call('GET', '/api/requests/9999999')
    check('несуществующая заявка: 404', code == 404 and d['ok'] is False, d)

    print('== ТК-17. Отчёты и выгрузка')
    code, d = disp.call('GET', '/api/reports/requests?format=json')
    if code in (404, 405):
        skip('ТК-17 отчёты (модуль отчётов)')
    else:
        check('отчёт по заявкам (json): columns/rows/summary', code == 200 and {'columns', 'rows', 'summary'} <= set(d['data']), d)
        code, body, ctype = disp.call('GET', '/api/reports/requests?format=csv', raw=True)
        check('отчёт по заявкам (csv)', code == 200 and 'csv' in ctype.lower() and len(body) > 20, (code, ctype))
        code, body, ctype = disp.call('GET', '/api/reports/requests?format=xlsx', raw=True)
        check('отчёт по заявкам (xlsx): ZIP-контейнер', code == 200 and body[:2] == b'PK', (code, ctype))
        code, body, ctype = disp.call('GET', '/api/reports/requests?format=pdf', raw=True)
        check('отчёт «PDF» - печатная HTML-страница', code == 200 and 'html' in ctype.lower(), ctype)
        code, d = admin.call('GET', '/api/reports/paid?format=json')
        check('отчёт по платным услугам', code == 200 and 'rows' in d['data'], d)
        code, d = smirnov.call('GET', '/api/reports/requests?format=json')
        check('исполнитель не получает отчёты (403)', code == 403, d)

    print('== ТК-18. Недоступность внешних сервисов: очередь и повторы')
    sql("UPDATE settings SET `value` = '0' WHERE `key` = 'integrations_enabled'")
    sms_before = len(os.listdir(os.path.join(APP, 'storage', 'sms')))
    code, d = new_req('emergency')
    check('при недоступных интеграциях заявка создаётся (основная работа не блокируется)', code == 200 and d['data']['assignee'], d)
    rid_i = d['data']['id']
    rows = sql(f"SELECT `system`, status, attempts, last_error FROM integration_queue WHERE request_id = {rid_i} ORDER BY id").splitlines()
    byname = {r.split('\t')[0]: r.split('\t') for r in rows}
    check('сообщение в РСО: pending, попытка 1, причина сохранена', byname.get('rso', [''] * 4)[1:3] == ['pending', '1'] and 'недоступ' in byname['rso'][3], rows)
    check('SMS клиенту: pending', byname.get('sms', [''] * 4)[1] == 'pending', rows)
    check('SMS в storage/sms не отправлялась', len(os.listdir(os.path.join(APP, 'storage', 'sms'))) == sms_before)
    check('уведомление клиента пока в статусе new', sql(f"SELECT GROUP_CONCAT(DISTINCT status) FROM notifications WHERE request_id = {rid_i} AND channel = 'sms'") == 'new')
    out = cron('process_queue')
    check('до срока повтора записи не трогаются', sql(f"SELECT attempts FROM integration_queue WHERE request_id = {rid_i} AND `system` = 'rso'") == '1', out)
    sql(f"UPDATE integration_queue SET next_try_at = NOW() - INTERVAL 1 MINUTE WHERE request_id = {rid_i}")
    cron('process_queue')
    check('повтор при недоступности: attempts = 2, всё ещё pending', sql(f"SELECT CONCAT(status, attempts) FROM integration_queue WHERE request_id = {rid_i} AND `system` = 'rso'") == 'pending2')
    sql("UPDATE settings SET `value` = '1' WHERE `key` = 'integrations_enabled'")
    sql(f"UPDATE integration_queue SET next_try_at = NOW() - INTERVAL 1 MINUTE WHERE request_id = {rid_i}")
    out = cron('process_queue')
    check('сервис доступен: повтор доставил сообщения (sent)', sql(f"SELECT COUNT(*) FROM integration_queue WHERE request_id = {rid_i} AND status = 'sent'") == str(len(rows)), out)
    check('SMS появилась в storage/sms, уведомление клиента sent', len(os.listdir(os.path.join(APP, 'storage', 'sms'))) > sms_before
          and sql(f"SELECT GROUP_CONCAT(DISTINCT status) FROM notifications WHERE request_id = {rid_i} AND channel = 'sms'") == 'sent')
    sql(f"INSERT INTO integration_queue (`system`, request_id, direction, payload, status, attempts, next_try_at, created_at) VALUES ('rso', {rid_i}, 'out', '{{}}', 'pending', 4, NOW() - INTERVAL 1 MINUTE, NOW())")
    sql("UPDATE settings SET `value` = '0' WHERE `key` = 'integrations_enabled'")
    cron('process_queue')
    check('после исчерпания попыток статус error', sql(f"SELECT status FROM integration_queue WHERE request_id = {rid_i} ORDER BY id DESC LIMIT 1") == 'error')
    sql("UPDATE settings SET `value` = '1' WHERE `key` = 'integrations_enabled'")
    code, d = admin.call('GET', '/api/integrations/queue')
    if code in (404, 405):
        skip('GET /integrations/queue (модуль администрирования)')
    else:
        check('очередь интеграций доступна администратору', code == 200, d)
    check('письма исполнителям дублируются в storage/mails', len(os.listdir(os.path.join(APP, 'storage', 'mails'))) > 1)

    print(f'\nПройдено: {passed}, пропущено: {skipped}, провалено: {len(fails)}')
    if fails:
        print('Провалены:', *fails, sep='\n  - ')
    return 1 if fails else 0


def as_user_by_sql(stamp, name):
    return as_user(f'test.{name}{stamp}')


if __name__ == '__main__':
    sys.exit(run())
