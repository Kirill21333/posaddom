// Администрирование: пользователи, правила назначения, настройки, журнал аудита, резервная копия
import * as api from './api.js';
import {
  h, mount, field, select, modal, confirmDialog, toast, toastErr, errorBox, showErr, debounce, dataTable, asList,
  fmtDMYHM, pager, kvRows, checkbox,
} from './ui.js';

const ACTIONS = {
  'auth.login': 'Вход в систему', 'auth.logout': 'Выход', 'auth.login_failed': 'Неудачная попытка входа', 'auth.login_blocked': 'Вход заблокирован',
  'auth.locked': 'Учётная запись заблокирована', 'auth.password': 'Смена пароля',
  'request.create': 'Создана заявка', 'request.update': 'Изменена заявка', 'request.status': 'Смена статуса заявки', 'request.assign': 'Ручное назначение',
  'request.auto_assign': 'Автоназначение', 'request.comment': 'Комментарий к заявке', 'request.photo': 'Загружено фото', 'photo.delete': 'Удалено фото',
  'client.create': 'Создан клиент', 'client.update': 'Изменён клиент', 'client.duplicate_confirmed': 'Подтверждён дубль клиента',
  'service.create': 'Создана услуга', 'service.update': 'Изменена услуга', 'service.delete': 'Удалена услуга',
  'service_category.create': 'Создана категория услуг', 'service_category.update': 'Изменена категория услуг',
  'employee.create': 'Создан сотрудник', 'employee.update': 'Изменён сотрудник', 'employee.schedule': 'Изменён график', 'employee.services': 'Изменена специализация',
  'employee.houses': 'Изменены закреплённые дома', 'employee.exception': 'Исключение из графика', 'employee.exception_delete': 'Удалено исключение из графика',
  'house.create': 'Создан дом', 'house.update': 'Изменён дом', 'premise.create': 'Создано помещение', 'premise.update': 'Изменено помещение',
  'rule.create': 'Создано правило назначения', 'rule.update': 'Изменено правило назначения', 'rule.delete': 'Удалено правило назначения',
  'user.create': 'Создан пользователь', 'user.update': 'Изменён пользователь', 'user.reset_password': 'Сброс пароля', 'user.unlock': 'Разблокирована учётная запись',
  'settings.change': 'Изменены настройки', 'backup.create': 'Резервная копия', 'backup.download': 'Скачана резервная копия', 'report.export': 'Выгрузка отчёта',
  'integration.retry': 'Повтор отправки в интеграцию', 'cron.check_deadlines': 'Проверка сроков (cron)', 'cron.process_queue': 'Очередь интеграций (cron)', 'cron.cleanup': 'Очистка (cron)',
};
const asName = (v) => (v && typeof v === 'object' ? (v.name || v.code) : v) || '';
const roleCode = (r) => (r && typeof r === 'object' ? r.code : r);

// ================================================================ пользователи
export async function users(ctx) {
  ctx.header('Пользователи');
  const ref = await ctx.ref();
  let people = [];
  try { people = asList(await api.get('/api/employees')); } catch (e) { /* noop */ }
  const roles = (ref.roles || []).map((r) => ({ value: r.code, label: r.name }));
  const box = h('div');
  const err = errorBox();

  async function load() {
    err.hidden = true;
    let items;
    try { items = asList(await api.get('/api/users')); } catch (e) { showErr(err, e); return; }
    mount(box, dataTable([
      { label: 'Логин', w: 150, render: (u) => u.login },
      { label: 'Сотрудник', w: 260, render: (u) => u.full_name || u.employee_name || (u.employee && u.employee.full_name) || '' },
      { label: 'Роль', w: 150, render: (u) => asName(u.role_name || u.role) },
      { label: 'Состояние', w: 190, render: (u) => (u.locked_until && new Date(u.locked_until.replace(' ', 'T')) > new Date() ? h('span', { class: 'over' }, 'заблокирован до ' + fmtDMYHM(u.locked_until)) : (u.is_active ? 'активен' : h('span', { class: 'warn' }, 'отключён'))) },
      { label: 'Последний вход', w: 150, render: (u) => (u.last_login_at ? fmtDMYHM(u.last_login_at) : '-') },
    ], items, { onRow: (u) => edit(u), empty: 'Пользователей нет' }));
  }

  function edit(u) {
    const isNew = !u;
    const v = u || { is_active: 1 };
    const f = {
      login: h('input', { type: 'text', value: v.login || '', disabled: !isNew, autocomplete: 'off' }),
      role: select(roles, roleCode(v.role) || 'employee'),
      employee_id: select([{ value: '', label: 'Не привязан' }, ...people.map((p) => ({ value: p.id, label: p.full_name }))], v.employee_id || (v.employee && v.employee.id) || ''),
      email: h('input', { type: 'email', value: v.email || '' }),
      password: h('input', { type: 'text', autocomplete: 'off', placeholder: 'не менее 8 символов, буквы и цифры' }),
    };
    const active = h('input', { type: 'checkbox', checked: !!v.is_active });
    const err2 = errorBox();
    const footer = [
      h('button', { class: 'btn', onClick: async (ev) => {
        const data = { role: f.role.value, employee_id: f.employee_id.value ? +f.employee_id.value : null, email: f.email.value.trim() || null, is_active: active.checked ? 1 : 0 };
        if (isNew) { data.login = f.login.value.trim(); data.password = f.password.value; }
        ev.target.disabled = true;
        try {
          if (isNew) await api.post('/api/users', data); else await api.put('/api/users/' + u.id, data);
          m.close(); toast('Сохранено', 'ok'); load();
        } catch (ex) { showErr(err2, ex); ev.target.disabled = false; }
      } }, 'Сохранить'),
      h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
    ];
    if (!isNew) {
      footer.push(h('button', { class: 'btn sec', style: { marginLeft: 'auto' }, onClick: async () => {
        if (!(await confirmDialog(`Сбросить пароль пользователя ${u.login}? Будет выдан временный пароль.`, { ok: 'Сбросить' }))) return;
        try {
          const d = await api.post(`/api/users/${u.id}/reset-password`);
          const pw = d && (d.password || d.temp_password || d.new_password);
          mount(err2, pw ? `Временный пароль: ${pw} (сообщите пользователю, при входе потребуется смена)` : 'Пароль сброшен');
          err2.hidden = false; err2.style.borderColor = '#2E7D32'; err2.style.background = '#E8F5E9'; err2.style.color = '#1B5E20';
        } catch (ex) { showErr(err2, ex); }
      } }, 'Сбросить пароль'));
      footer.push(h('button', { class: 'btn sec', onClick: async () => {
        try { await api.post(`/api/users/${u.id}/unlock`); toast('Учётная запись разблокирована', 'ok'); m.close(); load(); } catch (ex) { showErr(err2, ex); }
      } }, 'Разблокировать'));
    }
    const m = modal({
      title: isNew ? 'Новый пользователь' : 'Пользователь ' + u.login,
      body: [field('Логин', f.login), field('Роль', f.role), field('Сотрудник', f.employee_id), field('E-mail', f.email),
        isNew ? field('Пароль', f.password) : null, h('label', { class: 'chk' }, active, ' учётная запись активна'), err2],
      footer,
    });
  }

  mount(ctx.root, h('div', { class: 'toolbar' }, h('button', { class: 'btn right', onClick: () => edit(null) }, 'Добавить пользователя')), err, box);
  await load();
}

// ================================================================ правила назначения
export async function rules(ctx) {
  ctx.header('Правила назначения');
  const ref = await ctx.ref();
  const box = h('div');
  const err = errorBox();

  async function load() {
    err.hidden = true;
    let items;
    try { items = asList(await api.get('/api/rules')); } catch (e) { showErr(err, e); return; }
    mount(box, dataTable([
      { label: 'Приоритет', w: 90, render: (r) => r.priority },
      { label: 'Категория заявки', w: 170, render: (r) => asName(r.request_category) || r.request_category_name || 'любая' },
      { label: 'Категория услуг', w: 200, render: (r) => asName(r.service_category) || r.service_category_name || 'любая' },
      { label: 'Участок', w: 160, render: (r) => asName(r.section) || r.section_name || 'любой' },
      { label: 'Макс. нагрузка', w: 120, cls: 'num', render: (r) => r.max_load },
      { label: 'Описание', render: (r) => r.description || r.name || '' },
    ], items, { onRow: (r) => edit(r), empty: 'Правил нет: действует нагрузка по умолчанию из настроек' }));
  }

  function edit(r) {
    const isNew = !r;
    const v = r || {};
    const idOf = (x) => (x && typeof x === 'object' ? (x.id ?? x.code) : x) ?? '';
    const f = {
      priority: h('input', { type: 'number', min: '1', value: v.priority ?? 10 }),
      request_category: select([{ value: '', label: 'Любая' }, ...ref.categories.map((c) => ({ value: c.code, label: c.name }))], v.request_category ? (v.request_category.code || '') : ''),
      service_category_id: select([{ value: '', label: 'Любая' }, ...(ref.service_categories || []).map((c) => ({ value: c.id, label: c.name }))], idOf(v.service_category) || v.service_category_id || ''),
      section_id: select([{ value: '', label: 'Любой' }, ...(ref.sections || []).map((c) => ({ value: c.id, label: c.name }))], idOf(v.section) || v.section_id || ''),
      max_load: h('input', { type: 'number', min: '1', value: v.max_load ?? 9 }),
    };
    const err2 = errorBox();
    const footer = [
      h('button', { class: 'btn', onClick: async (ev) => {
        const data = {
          priority: +f.priority.value, request_category_code: f.request_category.value || null, request_category: f.request_category.value || null,
          service_category_id: f.service_category_id.value ? +f.service_category_id.value : null,
          section_id: f.section_id.value ? +f.section_id.value : null, max_load: +f.max_load.value,
        };
        ev.target.disabled = true;
        try {
          if (isNew) await api.post('/api/rules', data); else await api.put('/api/rules/' + r.id, data);
          m.close(); toast('Правило сохранено', 'ok'); load();
        } catch (ex) { showErr(err2, ex); ev.target.disabled = false; }
      } }, 'Сохранить'),
      h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
    ];
    if (!isNew) footer.push(h('button', { class: 'btn danger', style: { marginLeft: 'auto' }, onClick: async () => {
      if (!(await confirmDialog('Удалить правило назначения?', { ok: 'Удалить' }))) return;
      try { await api.del('/api/rules/' + r.id); m.close(); toast('Правило удалено', 'ok'); load(); } catch (ex) { showErr(err2, ex); }
    } }, 'Удалить'));
    const m = modal({
      title: isNew ? 'Новое правило' : 'Правило назначения',
      body: [h('p', { class: 'muted', style: { marginBottom: '14px', fontSize: '12px' } }, 'Применяется правило с наименьшим приоритетом, подходящее по фильтрам. Пустой фильтр означает «любой».'),
        field('Приоритет (меньше = важнее)', f.priority), field('Категория заявки', f.request_category), field('Категория услуг', f.service_category_id),
        field('Участок', f.section_id), field('Максимальная нагрузка исполнителя', f.max_load), err2],
      footer,
    });
  }

  mount(ctx.root, h('div', { class: 'toolbar' }, h('button', { class: 'btn right', onClick: () => edit(null) }, 'Добавить правило')), err, box);
  await load();
}

// ================================================================ настройки
export async function settings(ctx) {
  ctx.header('Настройки');
  const box = h('div');
  const err = errorBox();
  const queueBox = h('div', { class: 'sec-block' });
  let items = [];

  async function load() {
    err.hidden = true;
    let d;
    try { d = await api.get('/api/settings'); } catch (e) { showErr(err, e); return; }
    // допускаем массив [{key,value,description}] или объект {key: value}
    items = Array.isArray(d) ? d : (d && Array.isArray(d.items) ? d.items : Object.entries(d || {}).map(([key, value]) => (value && typeof value === 'object' && 'value' in value ? { key, ...value } : { key, value })));
    const inputs = {};
    mount(box,
      items.map((s) => {
        const key = s.key || s.name;
        const val = s.value ?? '';
        const inp = (val === '0' || val === '1' || val === 0 || val === 1) && /^(is_|.*_enabled$|.*_consent$)/.test(key)
          ? select([{ value: '1', label: 'Да' }, { value: '0', label: 'Нет' }], String(val))
          : h('input', { type: 'text', value: val });
        inputs[key] = inp;
        return h('div', { class: 'set-row' }, h('div', null, h('b', null, s.title || s.label || s.description || key), h('div', null, h('small', null, key))), inp);
      }),
      h('div', { style: { marginTop: '18px', display: 'flex', gap: '12px' } },
        h('button', { class: 'btn', onClick: async (ev) => {
          const data = {};
          for (const [k, el] of Object.entries(inputs)) data[k] = el.value;
          ev.target.disabled = true;
          try { await api.put('/api/settings', data); toast('Настройки сохранены', 'ok'); load(); } catch (ex) { showErr(err, ex); }
          ev.target.disabled = false;
        } }, 'Сохранить настройки'),
        h('button', { class: 'btn sec', onClick: async (ev) => {
          ev.target.disabled = true;
          try { const b = await api.post('/api/backup'); toast('Резервная копия создана' + (b && (b.file || b.name) ? ': ' + (b.file || b.name) : ''), 'ok'); } catch (ex) { toastErr(ex); }
          ev.target.disabled = false;
        } }, 'Создать резервную копию')));
  }

  async function loadQueue() {
    try {
      const d = await api.get('/api/integrations/queue');
      const rows = asList(d);
      mount(queueBox, h('h2', null, 'Очередь интеграций'), rows.length ? dataTable([
        { label: 'Дата', w: 130, render: (q) => fmtDMYHM(q.created_at) },
        { label: 'Система', w: 110, render: (q) => q.system },
        { label: 'Направление', w: 120, render: (q) => q.direction },
        { label: 'Статус', w: 100, render: (q) => q.status },
        { label: 'Попыток', w: 80, cls: 'num', render: (q) => q.attempts ?? q.retry_count ?? '' },
        { label: 'Сообщение', render: (q) => q.error || q.last_error || q.message || '' },
      ], rows) : h('div', { class: 'empty' }, 'Очередь пуста'));
    } catch (e) { mount(queueBox); }
  }

  mount(ctx.root, err, box, queueBox);
  await load();
  loadQueue();
}

// ================================================================ журнал аудита
export async function audit(ctx) {
  ctx.header('Журнал');
  const PER = 50;
  const st = { user: ctx.query.user || '', action: ctx.query.action || '', page: +ctx.query.page || 1 };
  const user = h('input', { type: 'search', placeholder: 'Пользователь', value: st.user });
  const action = h('input', { type: 'search', placeholder: 'Действие', value: st.action });
  const box = h('div');
  const info = h('div', { class: 'under-table' });
  const err = errorBox();

  async function load() {
    ctx.setQuery({ user: st.user, action: st.action, page: st.page > 1 ? st.page : '' });
    err.hidden = true;
    let d;
    try { d = await api.get('/api/audit', { ...st, per_page: PER }); } catch (e) { showErr(err, e); return; }
    const items = asList(d);
    mount(box, dataTable([
      { label: 'Дата', w: 140, render: (a) => fmtDMYHM(a.created_at || a.at) },
      { label: 'Пользователь', w: 170, render: (a) => a.user_name || a.login || a.user || 'система' },
      { label: 'Действие', w: 200, render: (a) => a.action_label || ACTIONS[a.action] || a.action },
      { label: 'Объект', w: 140, render: (a) => [a.entity_type || a.object_type, a.entity_id || a.object_id].filter(Boolean).join(' #') },
      { label: 'Подробности', render: (a) => { const x = a.details ?? a.new_value ?? a.new ?? ''; return typeof x === 'string' ? x : JSON.stringify(x); } },
      { label: 'IP', w: 110, render: (a) => a.ip || '' },
    ], items, { empty: 'Записей нет' }));
    mount(info, `Показано ${items.length} из ${d.total ?? items.length} записей`, pager({ page: st.page, per: PER, total: d.total || 0, onPage: (p) => { st.page = p; load(); } }));
  }
  const dl = debounce(() => { st.page = 1; load(); }, 350);
  user.addEventListener('input', () => { st.user = user.value.trim(); dl(); });
  action.addEventListener('input', () => { st.action = action.value.trim(); dl(); });
  mount(ctx.root, h('div', { class: 'toolbar' }, user, action), err, box, info);
  await load();
}
