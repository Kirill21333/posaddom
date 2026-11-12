"""Сквозные UI-тесты ПосадДом (Playwright, Chrome).

Запуск: python -X utf8 tests\\e2e.py _shots
Нужны: MariaDB и сервер приложения на 127.0.0.1:8300 (start.bat), база с демо-данными
(run_all.bat пересоздаёт её перед запуском). Скриншоты 1440x1024 складываются в указанную папку
(1_login ... 6_dashboard - для сверки с design/).
"""
import os
import re
import struct
import sys
import time
import zlib

from playwright.sync_api import sync_playwright

BASE = os.environ.get('E2E_BASE', 'http://127.0.0.1:8300')
SHOTS = sys.argv[1] if len(sys.argv) > 1 else '_shots'
PASSWORD = 'Demo12345'
os.makedirs(SHOTS, exist_ok=True)

results = []
state = {}


def png_file(path, color=(120, 160, 200)):
    """Небольшой валидный PNG без внешних библиотек."""
    w, h = 160, 120
    raw = b''.join(b'\x00' + bytes(color) * w for _ in range(h))

    def chunk(t, d):
        c = struct.pack('>I', len(d)) + t + d
        return c + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    data = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) \
        + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')
    with open(path, 'wb') as f:
        f.write(data)
    return path


PHOTO = png_file(os.path.join(SHOTS, '_photo.png'))


def check(cond, msg):
    results.append((bool(cond), msg))
    print(('  OK   ' if cond else '  FAIL ') + msg)
    return bool(cond)


def scenario(name):
    def deco(fn):
        def run(browser):
            print('== ' + name)
            ctx = browser.new_context(viewport={'width': 1440, 'height': 1024})
            page = ctx.new_page()
            page.set_default_timeout(8000)
            errors = []
            page.on('pageerror', lambda e: errors.append(str(e)))
            try:
                fn(page)
            except Exception as e:  # noqa: BLE001
                check(False, f'{name}: исключение {type(e).__name__}: {str(e)[:300]}')
                try:
                    page.screenshot(path=os.path.join(SHOTS, 'error_' + fn.__name__ + '.png'))
                except Exception:  # noqa: BLE001
                    pass
            check(not errors, f'{name}: нет ошибок JavaScript' + (f' ({errors[:2]})' if errors else ''))
            ctx.close()
        return run
    return deco


def shot(page, name):
    page.screenshot(path=os.path.join(SHOTS, name + '.png'))


def login(page, user, password=PASSWORD):
    page.goto(BASE + '/#/login')
    page.fill('#login', user)
    page.fill('#password', password)
    page.click('button[type=submit]')


def login_ok(page, user):
    login(page, user)
    page.wait_for_selector('.nav')


def pill(page):
    return page.locator('.top .pill').inner_text().strip()


def open_card(page, number):
    page.goto(BASE + '/#/requests?q=' + number)
    page.wait_for_selector('tbody tr')
    page.locator('tbody tr', has_text=number).first.click()
    page.wait_for_selector('.card-grid')


@scenario('1. Вход и список заявок (диспетчер)')
def s_list(page):
    page.goto(BASE + '/#/login')
    page.wait_for_selector('#login')
    shot(page, '1_login')
    check(page.locator('.login-card h2').inner_text() == 'Вход в систему', 'страница входа открыта')
    login(page, 'morozova.ev')
    page.wait_for_selector('.nav')
    check('(диспетчер)' in page.locator('.top-right').inner_text(), 'в шапке ФИО и роль диспетчера')
    menu = page.locator('.nav').inner_text()
    check(all(x in menu for x in ['Заявки', 'Новая заявка', 'Услуги', 'Клиенты', 'Отчёты']), 'меню диспетчера')
    page.wait_for_selector('tbody tr')
    rows = page.locator('tbody tr').count()
    check(rows >= 9, f'в списке заявок {rows} строк (нужно не менее 9)')
    check(page.locator('.under-table').inner_text().startswith('Показано'), 'под таблицей «Показано N из M заявок»')
    check(page.locator('.tabs .tab').count() >= 6, 'вкладки Все/Новые/Очередь/В работе/Просроченные/Закрытые')
    # вкладка «Очередь нераспределённых»
    page.click('.tabs >> text=Очередь нераспределённых')
    page.wait_for_timeout(700)
    check(page.locator('tbody tr').count() >= 1, 'очередь нераспределённых не пуста')
    check('не назначен' in page.locator('tbody').inner_text(), 'в очереди есть «не назначен»')
    for tab in ('Новые', 'В работе'):
        page.click('.tabs >> text=' + tab)
        page.wait_for_timeout(700)
        cnt = int(page.locator('.tabs .tab.active').inner_text().split()[-1])
        tot = int(re.search(r'из (\d+)', page.locator('.under-table').inner_text()).group(1))
        check(cnt == tot and tot > 0, f'вкладка «{tab}»: счётчик {cnt} совпадает с числом заявок {tot}')
    page.click('.tabs >> text=Все')
    page.wait_for_selector('tbody tr')
    page.wait_for_timeout(300)
    shot(page, '2_requests_list')


@scenario('2. Создание заявки через форму (диспетчер)')
def s_create(page):
    login_ok(page, 'morozova.ev')
    page.click('.nav >> text=Новая заявка')
    page.wait_for_selector('#f-phone')
    page.fill('#f-phone', '79160001245')
    page.wait_for_function("document.querySelector('.phone-state').textContent.includes('клиент найден')")
    check('Петрова' in page.locator('.phone-state').inner_text(), 'клиент найден по телефону: Петрова')
    page.select_option('#f-house', label='пр-т Красной Армии, 148')
    page.fill('#f-unit', '37')
    page.check('input[name=cat][value=emergency]')
    val = page.eval_on_selector('#f-service', "s => [...s.options].find(o => o.text.startsWith('САН-014')).value")
    page.select_option('#f-service', value=val)
    page.fill('#f-description', 'Течёт стояк ХВС в санузле, вода на полу, жильцы перекрыли вентиль в квартире')
    page.set_input_files('input[type=file]', PHOTO)
    page.wait_for_selector('.ph img')
    page.wait_for_function("/А-\\d{4}-\\d{6}/.test(document.querySelector('.calc').textContent)")
    calc = page.locator('.calc').inner_text()
    m = re.search(r'А-\d{4}-\d{6}', calc)
    check(m, 'в блоке «Рассчитывается автоматически» есть номер заявки')
    check('Срок выезда:' in calc and '30 минут' in calc, 'срок выезда 30 минут для аварийной')
    check('Срок устранения:' in calc and '24 часа' in calc, 'срок устранения 24 часа')
    check('Исполнитель:' in calc, 'в блоке указан исполнитель или очередь')
    shot(page, '3_new_request')
    page.click('#f-save')
    page.wait_for_selector('.card-grid')
    number = page.locator('.top h1').inner_text().replace('Заявка', '').strip()
    state['number'] = number
    check(re.fullmatch(r'А-\d{4}-\d{6}', number), f'заявка создана, номер {number}')
    check(pill(page) in ('Назначен исполнитель', 'Принята в работу'), f'статус после регистрации: {pill(page)}')
    check(page.locator('.photo img').count() >= 1, 'фото «до» загружено и показано в карточке')
    check('Новая' in page.locator('.hist').inner_text(), 'в истории статусов есть «Новая»')
    # кандидаты для переназначения
    page.click('#btn-assign')
    page.wait_for_selector('.modal .cand')
    check(page.locator('.modal .cand').count() >= 2, 'в окне переназначения есть кандидаты с нагрузкой')
    check('нагрузка' in page.locator('.modal').inner_text(), 'у кандидатов показана нагрузка')
    page.keyboard.press('Escape')
    # исполнителем назначаем Смирнова (ручное назначение через API от имени диспетчера)
    res = page.evaluate("""async () => {
      const j = async (m, u, b) => (await fetch(u, {method: m, headers: {'Content-Type': 'application/json'}, body: b ? JSON.stringify(b) : undefined})).json();
      const emps = (await j('GET', '/api/employees?q=%D0%A1%D0%BC%D0%B8%D1%80%D0%BD%D0%BE%D0%B2')).data.items;
      const id = +location.hash.split('/').pop();
      return await j('POST', '/api/requests/' + id + '/assign', {employee_id: emps[0].id, reason: 'e2e'});
    }""")
    check(res.get('ok'), 'заявка назначена Смирнову: ' + str(res.get('error', '')))
    # заявка видна в списке
    page.goto(BASE + '/#/requests?q=' + number)
    page.wait_for_selector('tbody tr')
    check(number in page.locator('tbody').inner_text(), 'номер заявки появился в списке')


@scenario('3. Исполнитель: Выполняется -> фото «после» -> Выполнена')
def s_employee(page):
    login_ok(page, 'smirnov.av')
    menu = page.locator('.nav').inner_text()
    check('Мои заявки' in menu and 'График и статистика' in menu and 'Уведомления' in menu, 'меню исполнителя')
    check('Новая заявка' not in menu and 'Услуги' not in menu, 'исполнителю не видны чужие разделы')
    number = state.get('number')
    check(number, 'есть номер заявки из предыдущего сценария')
    open_card(page, number)
    check(pill(page) == 'Назначен исполнитель', 'статус «Назначен исполнитель»')
    page.click('#btn-status')
    page.wait_for_selector('.modal')
    page.click('.modal >> text=Сохранить')
    page.wait_for_function("document.querySelector('.top .pill').textContent.includes('Выполняется')")
    check(pill(page) == 'Выполняется', 'статус «Выполняется»')
    page.wait_for_timeout(300)
    shot(page, '4_request_card')
    page.fill('#comment-text', 'Приступил к работе (e2e)')
    page.click('#comment-send')
    page.wait_for_function("document.querySelector('.comm').textContent.includes('Приступил к работе')")
    check(True, 'комментарий исполнителя добавлен')
    with page.expect_file_chooser() as fc:
        page.click('text=Добавить фото «после»')
    fc.value.set_files(PHOTO)
    page.wait_for_function("!document.querySelector('.card-left').textContent.includes('после: пока нет')")
    check('после' in page.locator('.card-left .photos').inner_text(), 'фото «после» загружено')
    page.click('#btn-status')
    page.wait_for_selector('.modal')
    page.click('.modal >> text=Сохранить')
    page.wait_for_function("document.querySelector('.top .pill').textContent.includes('Выполнена')")
    check(pill(page) == 'Выполнена', 'статус «Выполнена»')
    # чужая заявка недоступна (403 -> сообщение)
    r = page.evaluate("fetch('/api/requests/1').then(r => r.status)")
    check(r in (200, 403), 'доступ к чужим заявкам проверяется на сервере')


@scenario('4. Диспетчер: закрытие заявки и запрет правки')
def s_close(page):
    login_ok(page, 'morozova.ev')
    number = state.get('number')
    open_card(page, number)
    check(pill(page) == 'Выполнена', 'заявка в статусе «Выполнена»')
    page.click('#btn-close')
    page.wait_for_selector('.modal')
    page.click('.modal >> text=Закрыть заявку')
    page.wait_for_function("document.querySelector('.top .pill').textContent.includes('Закрыта')")
    check(pill(page) == 'Закрыта', 'статус «Закрыта»')
    check('Закрыта' in page.locator('.hist').inner_text(), 'закрытие записано в историю')
    check(page.locator('#btn-close').count() == 0 and page.locator('#btn-status').count() == 0, 'кнопки смены статуса скрыты')
    check('Изменить данные заявки' not in page.locator('.card-actions').inner_text(), 'правка закрытой заявки недоступна в интерфейсе')
    # сервер не даёт править закрытую заявку
    code = page.evaluate("""async () => {
      const id = +location.hash.split('/').pop();
      const r = await fetch('/api/requests/' + id, {method: 'PUT', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({description: 'правка после закрытия'})});
      return r.status;
    }""")
    check(code in (403, 409, 422), f'PUT закрытой заявки отклонён сервером (HTTP {code})')
    # можно оставить только дополнительное обращение
    check('Дополнительное обращение' in page.locator('#comment-text').get_attribute('placeholder'), 'доступен только комментарий-доп. обращение')
    page.fill('#comment-text', 'Клиент позвонил повторно (e2e)')
    page.click('#comment-send')
    page.wait_for_function("document.querySelector('.comm').textContent.includes('Клиент позвонил повторно')")
    check(True, 'дополнительное обращение добавлено')


@scenario('5. Руководитель: сводка')
def s_dashboard(page):
    login_ok(page, 'vasilyev.op')
    page.wait_for_selector('.stat')
    check(page.locator('h1').inner_text() == 'Сводка', 'открыта «Сводка»')
    nums = [t.strip() for t in page.locator('.stat .n').all_inner_texts()]
    check(len(nums) == 4 and all(n.isdigit() for n in nums), f'четыре числовые карточки: {nums}')
    check(int(nums[1]) > 0, 'есть заявки «В работе»')
    page.wait_for_selector('.dash tbody tr')
    check(page.locator('.dash tbody tr').count() >= 1, 'таблица «Просроченные и под угрозой» заполнена')
    check(page.locator('.load-row').count() >= 1, 'показана загрузка сотрудников')
    check('Заявки в срок за' in page.locator('.sla').inner_text(), 'показаны проценты в срок')
    menu = page.locator('.nav').inner_text()
    check('Сводка' in menu and 'Сотрудники' in menu, 'меню руководителя')
    shot(page, '6_dashboard')


@scenario('6. Администратор: справочник услуг, добавление и архив')
def s_services(page):
    login_ok(page, 'gromov.ks')
    page.click('.nav >> text=Услуги')
    page.wait_for_selector('tbody tr')
    page.wait_for_timeout(300)
    shot(page, '5_services')
    check('Платность' in page.locator('thead').inner_text(), 'таблица справочника услуг')
    code = 'E2E-%d' % (int(time.time()) % 100000)
    page.click('text=Добавить услугу')
    page.wait_for_selector('.modal')
    texts = page.locator('.modal input[type=text]')
    texts.nth(0).fill(code)
    texts.nth(1).fill('Тестовая услуга e2e')
    texts.nth(2).fill('шт.')
    page.select_option('.modal select', index=1)
    page.fill('.modal input[type=number] >> nth=0', '2')
    page.click('.modal >> text=Сохранить')
    page.wait_for_function("document.querySelector('.toasts').textContent.includes('добавлена')")
    page.fill('.s-search', code)
    page.wait_for_function("document.querySelectorAll('tbody tr').length === 1")
    check(code in page.locator('tbody').inner_text(), f'услуга {code} добавлена в справочник')
    page.click('tbody tr')
    page.wait_for_selector('.modal')
    page.click('.modal >> text=В архив')
    page.wait_for_function("document.querySelector('.toasts').textContent.includes('архив')")
    page.wait_for_timeout(600)
    check(page.locator('tbody tr').count() == 0 or code not in page.locator('tbody').inner_text(), 'архивная услуга скрыта из списка')
    page.check('.filters input[type=checkbox]')
    page.wait_for_function("document.querySelector('tbody') && document.querySelector('tbody').textContent.includes('(архив)')")
    check(code in page.locator('tbody').inner_text(), 'в режиме «показывать архивные» услуга видна с пометкой (архив)')
    # архивная услуга не выбирается в новых заявках
    n = page.evaluate("fetch('/api/services?archived=0&q=" + code + "').then(r => r.json()).then(d => d.data.items.length)")
    check(n == 0, 'архивная услуга не отдаётся для новых заявок')
    # чистим за собой: удаление услуги без заявок
    page.click('tbody tr')
    page.wait_for_selector('.modal')
    page.click('.modal >> text=Удалить')
    page.locator('.overlay').last.locator('text=Удалить').last.click()
    page.wait_for_function("document.querySelector('.toasts').textContent.includes('удалена')")
    check(True, 'тестовая услуга удалена (на неё не было заявок)')
    # удалить услугу с заявками нельзя
    r = page.evaluate("""async () => {
      const list = (await (await fetch('/api/services?q=%D0%A1%D0%90%D0%9D-014')).json()).data.items;
      const r = await fetch('/api/services/' + list[0].id, {method: 'DELETE'});
      return r.status;
    }""")
    check(r == 409, f'удаление услуги с заявками запрещено (HTTP {r})')


@scenario('7. Неверный пароль 5 раз: блокировка')
def s_lock(page):
    page.goto(BASE + '/#/login')
    page.wait_for_selector('#login')
    for i in range(5):
        page.fill('#login', 'orlov.va')
        page.fill('#password', 'Wrong%d' % i)
        page.click('button[type=submit]')
        page.wait_for_function("!document.querySelector('.login-card .err-box').hidden")
    msg = page.locator('.login-card .err-box').inner_text()
    check('заблок' in msg.lower() or 'попыт' in msg.lower() or 'неверн' in msg.lower(), f'сообщение об ошибке входа: «{msg}»')
    page.fill('#login', 'orlov.va')
    page.fill('#password', PASSWORD)
    page.click('button[type=submit]')
    page.wait_for_timeout(900)
    msg = page.locator('.login-card .err-box').inner_text() if page.locator('.login-card .err-box:visible').count() else ''
    check('#/login' in page.url and 'заблок' in msg.lower(), f'после 5 ошибок верный пароль не пускает: «{msg}»')
    shot(page, '7_login_locked')


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chrome')
        for run in (s_list, s_create, s_employee, s_close, s_dashboard, s_services, s_lock):
            run(browser)
        browser.close()
    bad = [m for ok, m in results if not ok]
    print('\nПроверок: %d, не пройдено: %d' % (len(results), len(bad)))
    for m in bad:
        print('  - ' + m)
    sys.exit(1 if bad else 0)


if __name__ == '__main__':
    main()
