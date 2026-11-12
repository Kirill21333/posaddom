# -*- coding: utf-8 -*-
"""Интеграционные тесты второй части API ПосадДом: услуги, сотрудники, клиенты, сводка, отчёты,
администрирование, уведомления.

Запуск (сервер на 127.0.0.1:8300, БД установлена с --demo):
    python -X utf8 tests/api_part2_test.py
Переменные окружения: PD_BASE (адрес сервера), PD_APP_DIR (корень приложения, для проверки резервных копий).
"""
import http.cookiejar
import io
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from datetime import date, timedelta
import random

BASE = os.environ.get('PD_BASE', 'http://127.0.0.1:8300')
APP_DIR = os.environ.get('PD_APP_DIR', os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))
PASS = 'Demo12345'
SUFFIX = '%04d' % random.randint(0, 9999)


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def call(self, method, path, body=None, raw=False):
        data = None
        headers = {'Accept': 'application/json'}
        if body is not None:
            data = json.dumps(body).encode()
            headers['Content-Type'] = 'application/json'
        req = urllib.request.Request(BASE + urllib.parse.quote(path, safe='/?&=:+%,'), data=data, method=method, headers=headers)
        try:
            r = self.op.open(req)
            payload, code, hdrs = r.read(), r.status, r.headers
        except urllib.error.HTTPError as e:
            payload, code, hdrs = e.read(), e.code, e.headers
        if raw:
            return code, payload, hdrs
        try:
            return code, json.loads(payload)
        except Exception:
            return code, payload.decode('utf-8', 'replace')

    def get(self, path):
        return self.call('GET', path)

    def post(self, path, body=None):
        return self.call('POST', path, body if body is not None else {})

    def put(self, path, body=None):
        return self.call('PUT', path, body if body is not None else {})

    def delete(self, path):
        return self.call('DELETE', path)

    def login(self, login, password=PASS):
        return self.post('/api/auth/login', {'login': login, 'password': password})


passed = 0
fails = []


def check(name, cond, info=''):
    global passed
    if cond:
        passed += 1
        print('  ok   ' + name)
    else:
        fails.append(name)
        print('  FAIL ' + name + ((' -> ' + str(info)[:300]) if info != '' else ''))


def data(resp):
    code, d = resp
    return d.get('data') if isinstance(d, dict) else None


def is_err(resp, status):
    code, d = resp
    return code == status and isinstance(d, dict) and d.get('ok') is False and bool(d.get('error'))


def ok(resp):
    code, d = resp
    return code == 200 and isinstance(d, dict) and d.get('ok') is True


def session(login):
    c = Client()
    code, d = c.login(login)
    if code != 200:
        raise SystemExit('Не удалось войти как %s: %s %s' % (login, code, d))
    return c


def section(t):
    print('\n== ' + t)


def main():
    anon = Client()
    adm = session('gromov.ks')
    dsp = session('morozova.ev')
    mgr = session('vasilyev.op')
    emp = session('smirnov.av')

    # ------------------------------------------------------------------ права по ролям
    section('Права по ролям (проверка на сервере)')
    check('Без входа: услуги -> 401', is_err(anon.get('/api/services'), 401))
    check('Без входа: сводка -> 401', is_err(anon.get('/api/dashboard'), 401))
    check('Сотрудник видит справочник услуг (200)', ok(emp.get('/api/services')))
    check('Сотрудник: создание услуги -> 403', is_err(emp.post('/api/services', {'code': 'X-1'}), 403))
    check('Сотрудник: список сотрудников -> 403', is_err(emp.get('/api/employees'), 403))
    check('Сотрудник: клиенты -> 403', is_err(emp.get('/api/clients'), 403))
    check('Сотрудник: сводка -> 403', is_err(emp.get('/api/dashboard'), 403))
    check('Сотрудник: отчёт -> 403', is_err(emp.get('/api/reports/requests'), 403))
    check('Сотрудник: пользователи -> 403', is_err(emp.get('/api/users'), 403))
    check('Сотрудник: журнал аудита -> 403', is_err(emp.get('/api/audit'), 403))
    check('Сотрудник: чужая карточка сотрудника -> 403', is_err(emp.get('/api/employees/1'), 403))
    check('Диспетчер: создание услуги -> 403', is_err(dsp.post('/api/services', {'code': 'X-2'}), 403))
    check('Диспетчер: правка сотрудника -> 403', is_err(dsp.put('/api/employees/1', {}), 403))
    check('Диспетчер: сводка -> 403', is_err(dsp.get('/api/dashboard'), 403))
    check('Диспетчер: настройки -> 403', is_err(dsp.get('/api/settings'), 403))
    check('Диспетчер: отчёт по исполнителям -> 403', is_err(dsp.get('/api/reports/executors'), 403))
    check('Диспетчер: отчёт по заявкам доступен', ok(dsp.get('/api/reports/requests')))
    check('Диспетчер: создание дома -> 403', is_err(dsp.post('/api/houses', {'address': 'x'}), 403))
    check('Руководитель: сводка доступна', ok(mgr.get('/api/dashboard')))
    check('Руководитель: создание услуги -> 403', is_err(mgr.post('/api/services', {'code': 'X-3'}), 403))
    check('Руководитель: правила назначения читает', ok(mgr.get('/api/rules')))
    check('Руководитель: создание правила -> 403', is_err(mgr.post('/api/rules', {'name': 'x', 'max_load': 5}), 403))
    check('Руководитель: резервная копия -> 403', is_err(mgr.post('/api/backup'), 403))
    check('Руководитель: настройки -> 403', is_err(mgr.put('/api/settings', {'default_max_load': 5}), 403))

    # ------------------------------------------------------------------ услуги
    section('Справочник услуг')
    items = data(adm.get('/api/services'))['items']
    codes = {s['code']: s for s in items}
    check('В справочнике не менее 20 услуг', len(items) >= 20, len(items))
    check('Есть САН-014 «Устранение протечки стояка ХВС»', 'САН-014' in codes and 'ХВС' in codes['САН-014']['name'])
    paid = data(adm.get('/api/services?paid=1'))['items']
    check('Фильтр paid=1: платные САН-021 и УБР-003', {'САН-021', 'УБР-003'} <= {s['code'] for s in paid} and all(s['is_paid'] for s in paid))
    check('САН-021 стоит 1800, основание - заявка собственника', codes['САН-021']['price'] == 1800 and 'собственника' in (codes['САН-021']['basis'] or ''))
    check('Бесплатная услуга: норм. документ ПП № 290', '290' in (codes['САН-001']['norm_document'] or ''))
    found = data(adm.get('/api/services?q=САН-014'))['items']
    check('Поиск по коду', len(found) == 1 and found[0]['code'] == 'САН-014')
    cat_id = codes['САН-001']['category_id']
    by_cat = data(adm.get('/api/services?category_id=%d' % cat_id))['items']
    check('Фильтр по категории', by_cat and all(s['category_id'] == cat_id for s in by_cat))
    cats = data(adm.get('/api/service-categories'))['items']
    check('Категории услуг: не менее 5', len(cats) >= 5, len(cats))

    code = 'ТСТ-' + SUFFIX
    check('Платная услуга без цены -> 422', is_err(adm.post('/api/services', {'code': code, 'name': 'Тест', 'category_id': cat_id, 'unit': 'шт.', 'norm_hours': 2, 'is_paid': 1}), 422))
    check('Услуга без названия -> 422', is_err(adm.post('/api/services', {'code': code, 'category_id': cat_id, 'unit': 'шт.', 'norm_hours': 2}), 422))
    r = adm.post('/api/services', {'code': code, 'name': 'Тестовая услуга', 'category_id': cat_id, 'unit': 'шт.', 'norm_hours': 2.5, 'is_paid': 1, 'price': 500})
    check('Создание платной услуги', ok(r) and data(r)['price'] == 500 and data(r)['basis'], r)
    sid = data(r)['id'] if ok(r) else 0
    check('Дубль кода -> 409', is_err(adm.post('/api/services', {'code': code, 'name': 'Ещё', 'category_id': cat_id, 'unit': 'шт.', 'norm_hours': 1}), 409))
    r = adm.put('/api/services/%d' % sid, {'price': 650, 'name': 'Тестовая услуга (изм.)'})
    check('Правка услуги: цена 650', ok(r) and data(r)['price'] == 650, r)
    ch = data(adm.get('/api/services/%d/changes' % sid))['items']
    fields = {c['field']: c for c in ch}
    check('Журнал изменений: создание, цена и название', {'created', 'price', 'name'} <= set(fields), list(fields))
    check('Журнал: цена 500 -> 650', fields['price']['old_value'] in ('500.00', '500') and fields['price']['new_value'] in ('650.00', '650'), fields.get('price'))
    check('Журнал: указан автор и время', fields['price']['user_name'] and fields['price']['changed_at'])
    r = adm.post('/api/services/%d/archive' % sid)
    check('Архивирование услуги', ok(r) and data(r)['is_archived'] == 1)
    check('Архивная услуга скрыта из списка по умолчанию', sid not in [s['id'] for s in data(adm.get('/api/services'))['items']])
    check('Архивная услуга видна при archived=1', sid in [s['id'] for s in data(adm.get('/api/services?archived=1'))['items']])
    ch = data(adm.get('/api/services/%d/changes' % sid))['items']
    check('Архивирование записано в журнал', any(c['field'] == 'is_archived' for c in ch))
    r = adm.post('/api/services/%d/restore' % sid)
    check('Восстановление из архива', ok(r) and data(r)['is_archived'] == 0)
    r = adm.delete('/api/services/%d' % codes['САН-014']['id'])
    check('Удаление услуги с заявками -> 409 (только архив)', is_err(r, 409) and 'архив' in r[1]['error'].lower(), r)
    check('Услуга САН-014 осталась', ok(adm.get('/api/services/%d' % codes['САН-014']['id'])))
    r = adm.delete('/api/services/%d' % sid)
    check('Удаление услуги без заявок разрешено', ok(r), r)
    check('Удалённая услуга -> 404', is_err(adm.get('/api/services/%d' % sid), 404))
    r = adm.post('/api/service-categories', {'name': 'Тест-категория ' + SUFFIX})
    check('Создание категории услуг', ok(r) and data(r)['id'])

    # ------------------------------------------------------------------ сотрудники
    section('Сотрудники')
    emps = data(adm.get('/api/employees'))['items']
    names = {e['short_name']: e for e in emps}
    check('Список сотрудников: не менее 11', len(emps) >= 11, len(emps))
    check('Есть Смирнов А.В. со слесарем-сантехником', 'Смирнов А.В.' in names and 'антехник' in names['Смирнов А.В.']['position']['name'])
    vac = data(adm.get('/api/employees?status=vacation'))['items']
    check('Фильтр по статусу: в отпуске - Иванова', len(vac) >= 1 and all(e['status']['code'] == 'vacation' for e in vac) and any('Иванова' in e['full_name'] for e in vac))
    sick = data(adm.get('/api/employees?status=sick'))['items']
    check('Фильтр по статусу: на больничном - Орлов', any('Орлов' in e['full_name'] for e in sick))
    q = data(adm.get('/api/employees?q=Гусев'))['items']
    check('Поиск по фамилии', len(q) == 1 and 'Гусев' in q[0]['full_name'])
    card = data(adm.get('/api/employees/%d' % names['Смирнов А.В.']['id']))
    check('Карточка: график пн-пт', len(card['schedule']) == 5 and [d['weekday'] for d in card['schedule']] == [1, 2, 3, 4, 5], card['schedule'])
    check('Карточка: специализация и дома', len(card['services']) >= 3 and len(card['houses']) >= 1)
    check('Карточка: нагрузка с предельной', card['load']['current'] >= 0 and card['load']['max'] >= 1)
    fed = data(adm.get('/api/employees/%d' % names['Фёдоров С.Е.']['id']))
    check('Круглосуточный график Фёдорова: 7 дней', len(fed['schedule']) == 7, len(fed['schedule']))

    login = 'test.emp' + SUFFIX
    pos = [e for e in emps if e['position']['is_executor']][0]['position']['id']
    sched = [e for e in emps][0]['schedule_type']['id']
    bad = adm.post('/api/employees', {'last_name': 'Тестов', 'first_name': '', 'position_id': pos, 'schedule_type_id': sched, 'phone': '+7 900 000-00-01'})
    check('Сотрудник без имени -> 422', is_err(bad, 422))
    r = adm.post('/api/employees', {'last_name': 'Тестов', 'first_name': 'Тест', 'middle_name': 'Тестович', 'position_id': pos, 'schedule_type_id': sched,
                                     'phone': '+7 900 000-00-01', 'email': 'test%s@example.ru' % SUFFIX, 'login': login, 'password': 'Test12345', 'role': 'employee'})
    check('Создание сотрудника с учётной записью', ok(r) and data(r)['user']['login'] == login, r)
    eid = data(r)['id']
    check('Диспетчер не может создать сотрудника (403)', is_err(dsp.post('/api/employees', {'last_name': 'А'}), 403))
    days = [{'weekday': d, 'shift_type': 'day', 'start_time': '08:00', 'end_time': '17:00', 'break_start': '12:00', 'break_end': '13:00'} for d in (1, 2, 3, 4)]
    days.append({'weekday': 6, 'shift_type': 'night', 'start_time': '22:00', 'end_time': '06:00'})
    exc_date = (date.today() + timedelta(days=3)).isoformat()
    r = adm.put('/api/employees/%d/schedule' % eid, {'days': days, 'exceptions': [{'date': exc_date, 'is_day_off': 1, 'reason': 'Отгул'}]})
    check('Сохранение графика и исключения', ok(r) and len(data(r)['schedule']) == 5 and len(data(r)['exceptions']) == 1, r)
    check('Время смены сохранено (08:00-17:00)', data(r)['schedule'][0]['start_time'] == '08:00' and data(r)['schedule'][0]['end_time'] == '17:00')
    check('Некорректное время -> 422', is_err(adm.put('/api/employees/%d/schedule' % eid, {'days': [{'weekday': 1, 'start_time': '25:00', 'end_time': '17:00'}]}), 422))
    check('Окончание раньше начала (дневная смена) -> 422', is_err(adm.put('/api/employees/%d/schedule' % eid, {'days': [{'weekday': 1, 'start_time': '18:00', 'end_time': '09:00'}]}), 422))
    svc_ids = [s['id'] for s in items[:4]]
    r = adm.put('/api/employees/%d/services' % eid, {'service_ids': svc_ids})
    check('Специализация: 4 услуги', ok(r) and sorted(s['id'] for s in data(r)['services']) == sorted(svc_ids), r)
    house_ids = [h['id'] for h in data(adm.get('/api/houses'))['items'][:2]]
    r = adm.put('/api/employees/%d/houses' % eid, {'house_ids': house_ids})
    check('Привязка к домам: 2 дома', ok(r) and sorted(h['id'] for h in data(r)['houses']) == sorted(house_ids), r)
    check('Несуществующая услуга в списке -> 422', is_err(adm.put('/api/employees/%d/services' % eid, {'service_ids': [99999999]}), 422))
    r = adm.put('/api/employees/%d' % eid, {'phone': '+7 900 000-00-02', 'status': 'vacation'})
    check('Правка сотрудника (телефон, статус)', ok(r) and data(r)['phone'] == '+7 900 000-00-02' and data(r)['status']['code'] == 'vacation', r)
    check('Исключение из графика удаляется', ok(adm.delete('/api/employees/%d/exceptions/%d' % (eid, data(adm.get('/api/employees/%d' % eid))['exceptions'][0]['id']))))
    # личный график и статистика
    r = emp.get('/api/me/schedule')
    d = data(r)
    check('/me/schedule: график сотрудника и ближайшие дни', ok(r) and len(d['schedule']) == 5 and len(d['upcoming']) == 14, r)
    check('/me/schedule: рабочие дни совпадают с пн-пт', all(u['working'] == (u['weekday'] <= 5) for u in d['upcoming'] if u['source'] == 'schedule'))
    r = emp.get('/api/me/stats')
    d = data(r)
    check('/me/stats: нагрузка и счётчики', ok(r) and all(k in d for k in ('load', 'max_load', 'open_requests', 'completed', 'in_time_percent', 'overdue_now')), r)
    check('Сотрудник читает только свою карточку', ok(emp.get('/api/employees/%d' % names['Смирнов А.В.']['id'])) and is_err(emp.get('/api/employees/%d' % names['Гусев Д.И.']['id']), 403))

    # ------------------------------------------------------------------ клиенты, дома
    section('Клиенты, дома и помещения')
    cl = data(dsp.get('/api/clients'))
    check('Список клиентов: не менее 15', cl['total'] >= 15, cl['total'])
    first = cl['items'][0]
    r = dsp.get('/api/clients?q=' + urllib.parse.quote(first['last_name']))
    check('Поиск клиента по фамилии', ok(r) and any(c['id'] == first['id'] for c in data(r)['items']))
    digits = ''.join(ch for ch in first['phone'] if ch.isdigit())[-7:]
    r = dsp.get('/api/clients?q=' + digits)
    check('Поиск клиента по части телефона', ok(r) and any(c['id'] == first['id'] for c in data(r)['items']), r)
    r = dsp.get('/api/clients/lookup?phone=' + urllib.parse.quote(first['phone']))
    check('Lookup по телефону находит клиента', ok(r) and data(r)['client'] and data(r)['client']['id'] in [c['id'] for c in cl['items'] if c['phone'] == first['phone']], r)
    r = dsp.get('/api/clients/lookup?phone=%2B79990001122')
    check('Lookup неизвестного телефона -> client = null', ok(r) and data(r)['client'] is None, r)
    with_req = [c for c in cl['items'] if c['requests_count'] > 0]
    r = dsp.get('/api/clients/%d' % with_req[0]['id'])
    d = data(r)
    check('Карточка клиента с историей обращений', ok(r) and len(d['requests']) == with_req[0]['requests_count'] and d['requests'][0]['number'] and d['requests'][0]['status']['name'], r)
    check('История: номер, дата, услуга, исполнитель, статус, дата закрытия',
          all(k in d['requests'][0] for k in ('number', 'received_at', 'service', 'assignee', 'status', 'closed_at')))
    check('Несуществующий клиент -> 404', is_err(dsp.get('/api/clients/99999999'), 404))

    houses = data(dsp.get('/api/houses'))['items']
    check('Список домов: не менее 8', len(houses) >= 8, len(houses))
    check('Поиск дома по адресу', any('Дружбы' in h['address'] for h in data(dsp.get('/api/houses?q=Дружбы'))['items']))
    hid = houses[0]['id']
    prem = data(dsp.get('/api/houses/%d/premises' % hid))
    check('Помещения дома (список)', isinstance(prem['items'], list))
    phone = '+7 (916) 555-%s' % (SUFFIX[:2] + '-' + SUFFIX[2:])
    newc = {'last_name': 'Тестовый', 'first_name': 'Клиент', 'middle_name': 'Иванович', 'phone': phone, 'status': 'owner', 'notify_consent': 1,
            'premise': {'house_id': hid, 'number': '9' + SUFFIX[:2]}}
    r = dsp.post('/api/clients', dict(newc, last_name='', first_name=''))
    check('Клиент без ФИО -> 422', is_err(r, 422))
    r = dsp.post('/api/clients', dict(newc, phone='123'))
    check('Некорректный телефон -> 422', is_err(r, 422))
    r = dsp.post('/api/clients', newc)
    check('Создание клиента с помещением', ok(r) and data(r)['premises'] and data(r)['premises'][0]['house_id'] == hid, r)
    cid = data(r)['id']
    r = dsp.post('/api/clients', newc)
    code, body = r
    check('Дубль (телефон + помещение) -> 409 с блоком duplicate', code == 409 and body.get('ok') is False and 'duplicate' in body and body['duplicate']['id'] == cid, r)
    check('Дубль: данные дублируются в data.duplicate', body.get('data', {}).get('duplicate', {}).get('id') == cid if isinstance(body, dict) else False)
    r = dsp.post('/api/clients', dict(newc, confirm_duplicate=True))
    check('Создание после confirm_duplicate=true', ok(r) and data(r)['id'] != cid, r)
    dup_id = data(r)['id'] if ok(r) else 0
    r = dsp.post('/api/clients', dict(newc, phone='+7 (916) 555-%s' % ('99-' + SUFFIX[2:]), premise={'house_id': hid, 'number': '9' + SUFFIX[:2]}))
    check('Тот же дом/помещение, другой телефон - не дубль', ok(r), r)
    r = dsp.put('/api/clients/%d' % cid, {'email': 'client%s@example.ru' % SUFFIX, 'notify_consent': 0})
    check('Правка клиента', ok(r) and data(r)['email'] == 'client%s@example.ru' % SUFFIX and data(r)['notify_consent'] == 0, r)
    check('Правка клиента сотрудником запрещена (403)', is_err(emp.put('/api/clients/%d' % cid, {'email': 'x@y.ru'}), 403))
    check('Руководитель читает клиентов, но не создаёт (403)', ok(mgr.get('/api/clients')) and is_err(mgr.post('/api/clients', newc), 403))
    r = adm.post('/api/houses', {'address': 'ул. Тестовая, %s' % SUFFIX, 'section_id': houses[0]['section_id'], 'floors': 5, 'entrances': 2, 'premises_count': 40, 'year_built': 1985})
    check('Админ создаёт дом', ok(r) and data(r)['address'].startswith('ул. Тестовая'), r)
    nh = data(r)['id']
    check('Дубль адреса дома -> 409', is_err(adm.post('/api/houses', {'address': 'ул. Тестовая, %s' % SUFFIX, 'section_id': houses[0]['section_id'], 'floors': 5, 'entrances': 2, 'premises_count': 40}), 409))
    r = adm.put('/api/houses/%d' % nh, {'floors': 9, 'is_active': 0})
    check('Админ правит дом', ok(r) and data(r)['floors'] == 9 and data(r)['is_active'] == 0, r)
    r = adm.post('/api/houses/%d/premises' % nh, {'number': '12', 'type': 'residential', 'area': 54.3})
    check('Админ добавляет помещение', ok(r) and data(r)['number'] == '12' and data(r)['area'] == 54.3, r)
    check('Помещение с тем же номером -> 409', is_err(adm.post('/api/houses/%d/premises' % nh, {'number': '12'}), 409))
    check('Диспетчер не правит дом (403)', is_err(dsp.put('/api/houses/%d' % nh, {'floors': 3}), 403))

    # ------------------------------------------------------------------ сводка
    section('Сводка руководителя')
    r = mgr.get('/api/dashboard')
    d = data(r)
    check('Сводка: счётчики', ok(r) and all(isinstance(d[k], int) for k in ('new_today', 'in_work', 'overdue', 'unassigned')), r)
    check('Сводка: список просроченных и под угрозой', isinstance(d['overdue_list'], list) and len(d['overdue_list']) >= 1)
    if d['overdue_list']:
        it = d['overdue_list'][0]
        check('Элемент списка: номер, адрес, исполнитель, статус, срок', all(k in it for k in ('number', 'house', 'assignee', 'status', 'due_at', 'arrive_due', 'label', 'kind')), it)
    check('Просроченные идут первыми, затем по остатку времени',
          [x['kind'] == 'overdue' for x in d['overdue_list']] == sorted([x['kind'] == 'overdue' for x in d['overdue_list']], reverse=True))
    check('Сводка: не менее 3 исполнителей в нагрузке', len(d['workload']) >= 3 and all('max_load' in w and 'load' in w and w['employee'] for w in d['workload']))
    check('Нагрузка отсортирована по убыванию', [w['load'] for w in d['workload']] == sorted([w['load'] for w in d['workload']], reverse=True))
    check('Исполнитель в отпуске/на больничном не в нагрузке', not any(w['employee'].startswith(('Иванова', 'Орлов')) for w in d['workload']))
    check('Доля в срок по трём категориям', set(d['sla']) == {'emergency', 'urgent', 'planned'} and all(v is None or 0 <= v <= 100 for v in d['sla'].values()), d['sla'])
    for p in ('today', 'week', 'month', 'quarter'):
        rr = mgr.get('/api/dashboard?period=' + p)
        check('Период %s принят' % p, ok(rr) and data(rr)['period'] == p)
    check('Неверный период -> 422', is_err(mgr.get('/api/dashboard?period=year'), 422))
    check('Фильтр по дому', ok(mgr.get('/api/dashboard?house=%d' % hid)))
    w = data(mgr.get('/api/dashboard?period=quarter'))
    check('За квартал заявок не меньше, чем за неделю', w['period_stats']['received'] >= data(mgr.get('/api/dashboard?period=week'))['period_stats']['received'])

    # ------------------------------------------------------------------ отчёты
    section('Отчёты')
    f = 'from=%s&to=%s' % ((date.today() - timedelta(days=150)).isoformat(), date.today().isoformat())
    for t in ('requests', 'executors', 'paid', 'overdue', 'clients'):
        r = mgr.get('/api/reports/%s?%s' % (t, f))
        d = data(r)
        check('Отчёт %s (json): columns, rows, summary' % t, ok(r) and d['columns'] and isinstance(d['rows'], list) and isinstance(d['summary'], dict)
              and all('key' in c and 'title' in c for c in d['columns']), r if not ok(r) else '')
    rq = data(mgr.get('/api/reports/requests?' + f))
    check('Отчёт по заявкам: не менее 30 строк с демо-данными', len(rq['rows']) >= 30, len(rq['rows']))
    check('Отчёт по заявкам: итог совпадает с числом строк', rq['summary']['total'] == len(rq['rows']))
    em = data(mgr.get('/api/reports/requests?%s&category=emergency' % f))
    check('Фильтр по категории (аварийные)', em['rows'] and all(x['category'] == 'Аварийная' for x in em['rows']) and len(em['rows']) < len(rq['rows']))
    cl_ = data(mgr.get('/api/reports/requests?%s&status=closed' % f))
    check('Фильтр по статусу (закрытые)', cl_['rows'] and all(x['status'] == 'Закрыта' for x in cl_['rows']))
    ex = data(mgr.get('/api/reports/executors?' + f))
    check('Исполнители: выполнено, среднее время, просрочки', any(r_['completed'] > 0 and r_['avg_hours'] is not None for r_ in ex['rows']) and all('overdue' in r_ for r_ in ex['rows']))
    pd = data(mgr.get('/api/reports/paid?' + f))
    check('Платные: сумма начислений = сумма строк', abs(sum(x['amount'] for x in pd['rows']) - pd['summary']['total_amount']) < 0.01 and pd['summary']['total_amount'] > 0, pd['summary'])
    ov = data(mgr.get('/api/reports/overdue?' + f))
    check('Просроченные: с величиной просрочки, по убыванию', ov['rows'] and all(x['overdue_minutes'] > 0 for x in ov['rows'])
          and [x['overdue_minutes'] for x in ov['rows']] == sorted([x['overdue_minutes'] for x in ov['rows']], reverse=True))
    cr = data(mgr.get('/api/reports/clients?' + f))
    check('Обращения клиентов: число обращений на клиента', cr['rows'] and cr['rows'][0]['requests'] >= cr['rows'][-1]['requests'] and cr['summary']['requests'] == sum(x['requests'] for x in cr['rows']))
    check('Неизвестный отчёт -> 404', is_err(mgr.get('/api/reports/nothing'), 404))
    check('Неверный формат даты -> 422', is_err(mgr.get('/api/reports/requests?from=01.01.2026'), 422))
    check('Неверный формат вывода -> 422', is_err(mgr.get('/api/reports/requests?format=doc'), 422))
    code, body, hdrs = mgr.call('GET', '/api/reports/requests?%s&format=csv' % f, raw=True)
    text = body.decode('utf-8-sig')
    check('CSV: тип, BOM и заголовки', code == 200 and 'text/csv' in hdrs.get('Content-Type', '') and body.startswith(b'\xef\xbb\xbf') and '№ заявки' in text.splitlines()[0])
    check('CSV: число строк = заявкам + итоги', len([l for l in text.splitlines() if l.startswith('"А-') or l.startswith('"С-') or l.startswith('"П-')]) == len(rq['rows']))
    check('CSV: имя файла в заголовке', 'report_requests' in hdrs.get('Content-Disposition', '') or 'report_requests' in urllib.parse.unquote(hdrs.get('Content-Disposition', '')))
    code, body, hdrs = mgr.call('GET', '/api/reports/paid?%s&format=xlsx' % f, raw=True)
    try:
        z = zipfile.ZipFile(io.BytesIO(body))
        sheet = z.read('xl/worksheets/sheet1.xml').decode('utf-8')
        good = 'xl/workbook.xml' in z.namelist() and sheet.count('<row ') >= len(pd['rows']) + 1
    except Exception as e:
        good, sheet = False, str(e)
    check('XLSX: корректный пакет с данными отчёта', code == 200 and body[:2] == b'PK' and 'spreadsheetml' in hdrs.get('Content-Type', '') and good, sheet[:100])
    code, body, hdrs = mgr.call('GET', '/api/reports/requests?%s&format=pdf' % f, raw=True)
    html = body.decode('utf-8')
    check('PDF: печатная HTML-страница с таблицей', code == 200 and 'text/html' in hdrs.get('Content-Type', '') and '@media print' in html and '<table>' in html and 'А-' in html)
    log = data(adm.get('/api/audit?action=report.export&per_page=100'))
    check('Выгрузки отчётов записаны в журнал аудита (csv, xlsx, pdf)', {json.loads(x['new_value'])['format'] for x in log['items']} >= {'csv', 'xlsx', 'pdf'}, log['total'])

    # ------------------------------------------------------------------ администрирование
    section('Администрирование')
    users = data(adm.get('/api/users'))['items']
    check('Список пользователей: не менее 11', len(users) >= 11, len(users))
    check('У пользователей есть роль и ФИО сотрудника', all(u['role'] and u['name'] for u in users))
    check('Фильтр по роли', all(u['role'] == 'employee' for u in data(adm.get('/api/users?role=employee'))['items']))
    ulogin = 'test.usr' + SUFFIX
    check('Слабый пароль -> 422', is_err(adm.post('/api/users', {'login': ulogin, 'password': 'abc', 'role': 'dispatcher'}), 422))
    check('Роль сотрудник без карточки -> 422', is_err(adm.post('/api/users', {'login': ulogin, 'password': 'Test12345', 'role': 'employee'}), 422))
    check('Дубль логина -> 409', is_err(adm.post('/api/users', {'login': 'gromov.ks', 'password': 'Test12345', 'role': 'admin'}), 409))
    r = adm.post('/api/users', {'login': ulogin, 'password': 'Test12345', 'role': 'dispatcher', 'email': ulogin + '@example.ru'})
    check('Создание пользователя', ok(r) and data(r)['role'] == 'dispatcher', r)
    uid = data(r)['id']
    t = Client()
    check('Новый пользователь входит в систему', t.login(ulogin, 'Test12345')[0] == 200)
    r = adm.put('/api/users/%d' % uid, {'role': 'manager'})
    check('Смена роли пользователя', ok(r) and data(r)['role'] == 'manager', r)
    check('Новая роль действует сразу (сводка доступна)', ok(t.get('/api/dashboard')))
    r = adm.put('/api/users/%d' % uid, {'is_active': 0})
    check('Отключение пользователя', ok(r) and data(r)['is_active'] == 0)
    check('Отключённая учётная запись не входит', t.login(ulogin, 'Test12345')[0] != 200)
    check('Отключённый пользователь теряет доступ в открытой сессии', t.get('/api/dashboard')[0] == 401)
    adm.put('/api/users/%d' % uid, {'is_active': 1})
    me_id = [u for u in users if u['login'] == 'gromov.ks'][0]['id']
    check('Администратор не может отключить себя (409)', is_err(adm.put('/api/users/%d' % me_id, {'is_active': 0}), 409))
    check('Администратор не может снять с себя роль (409)', is_err(adm.put('/api/users/%d' % me_id, {'role': 'manager'}), 409))
    # блокировка и разблокировка
    lt = Client()
    codes_ = [lt.login(ulogin, 'Wrong' + str(i))[0] for i in range(5)]
    check('Пять неверных паролей не дают войти', all(c != 200 for c in codes_), codes_)
    code, _ = lt.login(ulogin, 'Test12345')
    check('После пяти ошибок учётная запись заблокирована даже с верным паролем', code != 200, code)
    lu = [u for u in data(adm.get('/api/users'))['items'] if u['id'] == uid][0]
    check('В списке виден признак блокировки', lu['is_locked'] == 1 and lu['locked_until'], lu)
    r = adm.post('/api/users/%d/unlock' % uid)
    check('Разблокировка администратором', ok(r) and data(r)['is_locked'] == 0 and data(r)['failed_attempts'] == 0, r)
    check('После разблокировки вход возможен', Client().login(ulogin, 'Test12345')[0] == 200)
    r = adm.post('/api/users/%d/reset-password' % uid)
    new_pw = data(r)['password'] if ok(r) else ''
    check('Сброс пароля выдаёт временный пароль', ok(r) and len(new_pw) >= 8 and data(r)['user']['must_change_password'] == 1, r)
    check('Старый пароль не действует, новый - да', Client().login(ulogin, 'Test12345')[0] != 200 and Client().login(ulogin, new_pw)[0] == 200)
    check('Сброс пароля диспетчером запрещён (403)', is_err(dsp.post('/api/users/%d/reset-password' % uid), 403))
    check('Несуществующий пользователь -> 404', is_err(adm.post('/api/users/99999999/unlock'), 404))

    st = data(adm.get('/api/settings'))
    check('Настройки: все ключи из справочника', {'business_start', 'business_end', 'warn_percent', 'login_max_attempts', 'login_lock_minutes', 'photo_retention_years', 'default_max_load', 'integrations_enabled'} <= set(st['values']), list(st['values']))
    old = st['values']['default_max_load']
    r = adm.put('/api/settings', {'settings': {'default_max_load': '11'}})
    check('Изменение настройки default_max_load', ok(r) and data(r)['values']['default_max_load'] == '11', r)
    check('Изменение настройки видно в списке', data(adm.get('/api/settings'))['values']['default_max_load'] == '11')
    check('Некорректное значение настройки -> 422', is_err(adm.put('/api/settings', {'settings': {'default_max_load': 'много'}}), 422))
    check('Неизвестная настройка -> 422', is_err(adm.put('/api/settings', {'settings': {'no_such_key': '1'}}), 422))
    check('Время окончания раньше начала -> 422', is_err(adm.put('/api/settings', {'settings': {'business_end': '08:00'}}), 422))
    sa = data(adm.get('/api/audit?action=settings.change'))
    check('Смена настройки записана в аудит', any(json.loads(x['new_value']).get('default_max_load') == '11' for x in sa['items']), sa['total'])
    adm.put('/api/settings', {'settings': {'default_max_load': old}})

    r = adm.post('/api/rules', {'name': 'Тест аварийные ' + SUFFIX, 'request_category': 'emergency', 'priority': 3, 'max_load': 12, 'allow_off_schedule': 1})
    check('Создание правила автоназначения', ok(r) and data(r)['request_category']['code'] == 'emergency' and data(r)['max_load'] == 12 and data(r)['allow_off_schedule'] == 1, r)
    rid = data(r)['id']
    check('Правило без предельной нагрузки -> 422', is_err(adm.post('/api/rules', {'name': 'x'}), 422))
    r = adm.put('/api/rules/%d' % rid, {'max_load': 7, 'is_active': 0, 'section_id': houses[0]['section_id']})
    check('Правка правила', ok(r) and data(r)['max_load'] == 7 and data(r)['is_active'] == 0 and data(r)['section']['id'] == houses[0]['section_id'], r)
    check('Правило в списке', rid in [x['id'] for x in data(adm.get('/api/rules'))['items']])
    check('Удаление правила', ok(adm.delete('/api/rules/%d' % rid)) and is_err(adm.delete('/api/rules/%d' % rid), 404))

    au = data(adm.get('/api/audit?page=1&per_page=5'))
    check('Журнал аудита: пагинация', len(au['items']) == 5 and au['total'] > 5 and au['pages'] >= 2 and au['page'] == 1, au['total'])
    p2 = data(adm.get('/api/audit?page=2&per_page=5'))
    check('Журнал аудита: вторая страница отличается', p2['items'] and p2['items'][0]['id'] < au['items'][-1]['id'])
    uf = data(adm.get('/api/audit?action=user.&per_page=100'))
    check('Журнал: фильтр по действию', uf['items'] and all(x['action'].startswith('user.') for x in uf['items']))
    gf = data(adm.get('/api/audit?user=gromov&per_page=100'))
    check('Журнал: фильтр по пользователю', gf['items'] and all(x['user'] and x['user']['login'] == 'gromov.ks' for x in gf['items']))
    check('Журнал: в записи есть пользователь, действие, время, объект', all(k in au['items'][0] for k in ('user', 'action', 'created_at', 'entity_type', 'ip_address')))
    check('Журнал: создание пользователя зафиксировано', any(x['entity_id'] == uid for x in data(adm.get('/api/audit?action=user.create&per_page=100'))['items']))
    check('Журнал: правка услуги зафиксирована', data(adm.get('/api/audit?action=service.&per_page=100'))['total'] >= 3)
    check('Журнал: подтверждение дубля клиента', data(adm.get('/api/audit?action=client.duplicate_confirmed'))['total'] >= 1)

    before = set(os.listdir(os.path.join(APP_DIR, 'storage', 'backups'))) if os.path.isdir(os.path.join(APP_DIR, 'storage', 'backups')) else set()
    r = adm.post('/api/backup')
    check('Резервная копия создана', ok(r) and data(r)['name'].endswith('.sql') and data(r)['size'] > 1000, r)
    if ok(r):
        path = os.path.join(APP_DIR, 'storage', 'backups', data(r)['name'])
        dump = open(path, 'rb').read().decode('utf-8', 'replace') if os.path.isfile(path) else ''
        check('Файл копии в storage/backups содержит схему и данные', 'CREATE TABLE' in dump and 'requests' in dump and 'ПосадДом' in dump or 'posaddom' in dump.lower(), len(dump))
        check('Копия включает заявки и триггеры', 'INSERT INTO `requests`' in dump and 'TRIGGER' in dump)
        check('Копия появилась в списке', data(r)['name'] in [b['name'] for b in data(adm.get('/api/backups'))['items']])
        check('Метод копирования: ' + data(r)['method'], data(r)['method'] in ('mysqldump', 'php'))
    check('Резервная копия записана в аудит', data(adm.get('/api/audit?action=backup.create'))['total'] >= 1)

    q = data(adm.get('/api/integrations/queue'))
    check('Очередь интеграций: список и счётчики', isinstance(q['items'], list) and set(q['counts']) >= {'pending', 'sent', 'error'}, q.get('counts'))
    check('Очередь: фильтр по статусу', all(x['status'] == 'sent' for x in data(adm.get('/api/integrations/queue?status=sent'))['items']))
    check('Очередь доступна руководителю, но не диспетчеру', ok(mgr.get('/api/integrations/queue')) and is_err(dsp.get('/api/integrations/queue'), 403))

    # ------------------------------------------------------------------ уведомления
    section('Уведомления')
    n = data(emp.get('/api/notifications'))
    check('Уведомления сотрудника: список и счётчик непрочитанных', isinstance(n['items'], list) and isinstance(n['unread'], int), n)
    if n['items']:
        nid = n['items'][0]['id']
        unread0 = n['unread']
        r = emp.post('/api/notifications/%d/read' % nid)
        check('Прочитать уведомление', ok(r))
        n2 = data(emp.get('/api/notifications'))
        check('Счётчик непрочитанных не вырос', n2['unread'] <= unread0)
        check('Чужое уведомление прочитать нельзя (404)', is_err(dsp.post('/api/notifications/%d/read' % nid), 404))
    r = emp.post('/api/notifications/read-all')
    check('Прочитать все', ok(r) and data(emp.get('/api/notifications'))['unread'] == 0)
    check('Фильтр unread=1 пуст после read-all', data(emp.get('/api/notifications?unread=1'))['items'] == [])
    check('Диспетчер получает свои уведомления', ok(dsp.get('/api/notifications')))

    print('\nИтого: пройдено %d, не пройдено %d' % (passed, len(fails)))
    if fails:
        print('Не пройдены:\n - ' + '\n - '.join(fails))
        sys.exit(1)


if __name__ == '__main__':
    main()
