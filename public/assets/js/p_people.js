// Сотрудники и клиенты: списки и карточки
import * as api from './api.js';
import {
  h, mount, field, select, modal, toast, errorBox, showErr, debounce, dataTable, asList, kvRows, shortFio,
  fmtDMY, fmtDMYHM, fmtDMHM, splitFio, phoneInput, fmtNum, checkbox, addrOf,
} from './ui.js';
import { is } from './app.js';

const SHIFT = { day: 'дневная', night: 'ночная', full: 'круглосуточная' };
const asName = (v) => (v && typeof v === 'object' ? v.name : v) || '';

// ================================================================ сотрудники
export async function employees(ctx) {
  ctx.header('Сотрудники');
  const ref = await ctx.ref();
  const admin = is('admin');
  const st = { q: ctx.query.q || '', position: ctx.query.position || '', status: ctx.query.status || '' };
  const search = h('input', { type: 'search', placeholder: 'Поиск по ФИО или телефону', value: st.q });
  const posSel = select([{ value: '', label: 'Должность' }, ...(ref.positions || []).map((p) => ({ value: p.id, label: p.name }))], st.position);
  const statSel = select([{ value: '', label: 'Статус' }, ...(ref.employee_statuses || []).map((s) => ({ value: s.code, label: s.name }))], st.status);
  const box = h('div');
  const err = errorBox();

  async function load() {
    ctx.setQuery(st);
    err.hidden = true;
    let items;
    try { items = asList(await api.get('/api/employees', st)); } catch (e) { showErr(err, e); return; }
    mount(box, dataTable([
      { label: 'ФИО', w: 240, render: (e) => e.full_name },
      { label: 'Должность', w: 190, render: (e) => asName(e.position) },
      { label: 'Статус', w: 130, render: (e) => asName(e.status) },
      { label: 'График', w: 170, render: (e) => asName(e.schedule_type) },
      { label: 'Телефон', w: 140, render: (e) => e.phone },
      { label: 'Нагрузка', w: 90, render: (e) => (typeof e.load === 'number' ? `${e.load} из ${e.max_load ?? ''}` : '') },
      { label: 'Услуг', w: 70, cls: 'num', render: (e) => e.services_count },
      { label: 'Домов', w: 70, cls: 'num', render: (e) => e.houses_count },
    ], items, { onRow: (e) => ctx.go('/employees/' + e.id), empty: 'Сотрудники не найдены' }));
  }
  const dl = debounce(load, 300);
  search.addEventListener('input', () => { st.q = search.value.trim(); dl(); });
  posSel.addEventListener('change', () => { st.position = posSel.value; load(); });
  statSel.addEventListener('change', () => { st.status = statSel.value; load(); });
  mount(ctx.root, h('div', { class: 'toolbar' }, search, posSel, statSel,
    admin ? h('button', { class: 'btn right', onClick: () => employeeForm(ctx, ref, null, () => load()) }, 'Добавить сотрудника') : null), err, box);
  await load();
}

function employeeForm(ctx, ref, e, done) {
  const isNew = !e;
  const v = e || {};
  const f = {
    last_name: h('input', { type: 'text', value: v.last_name || '' }),
    first_name: h('input', { type: 'text', value: v.first_name || '' }),
    middle_name: h('input', { type: 'text', value: v.middle_name || '' }),
    position_id: select((ref.positions || []).map((p) => ({ value: p.id, label: p.name })), v.position && v.position.id),
    status: select((ref.employee_statuses || []).map((s) => ({ value: s.code, label: s.name })), v.status ? v.status.code : 'active'),
    schedule_type: select((ref.schedule_types || []).map((s) => ({ value: s.code, label: s.name })), v.schedule_type && v.schedule_type.code),
    phone: phoneInput({ value: v.phone || '' }),
    email: h('input', { type: 'email', value: v.email || '' }),
    hire_date: h('input', { type: 'date', value: v.hire_date || '' }),
  };
  const acc = { login: h('input', { type: 'text', autocomplete: 'off' }), password: h('input', { type: 'text', autocomplete: 'off', placeholder: 'не менее 8 символов, буквы и цифры' }),
    role: select((ref.roles || []).map((r) => ({ value: r.code, label: r.name })), 'employee') };
  const err = errorBox();
  const body = [
    field('Фамилия', f.last_name), field('Имя', f.first_name), field('Отчество', f.middle_name),
    field('Должность', f.position_id), field('Статус', f.status), field('Тип графика', f.schedule_type),
    field('Телефон', f.phone), field('E-mail', f.email), field('Дата приёма', f.hire_date),
  ];
  if (isNew) body.push(h('div', { class: 'sec-h' }, 'Учётная запись (необязательно)'), field('Логин', acc.login), field('Пароль', acc.password), field('Роль', acc.role));
  body.push(err);
  const m = modal({
    title: isNew ? 'Новый сотрудник' : 'Изменение сотрудника', wide: true, body,
    footer: [
      h('button', { class: 'btn', onClick: async (ev) => {
        const data = {};
        for (const [k, el] of Object.entries(f)) data[k] = el.value;
        if (isNew && acc.login.value.trim()) Object.assign(data, { login: acc.login.value.trim(), password: acc.password.value, role: acc.role.value });
        ev.target.disabled = true;
        try {
          if (isNew) await api.post('/api/employees', data); else await api.put('/api/employees/' + e.id, data);
          m.close(); toast('Сохранено', 'ok'); done();
        } catch (ex) { showErr(err, ex); ev.target.disabled = false; }
      } }, 'Сохранить'),
      h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
    ],
  });
}

export async function employee(ctx) {
  const ref = await ctx.ref();
  const admin = is('admin');
  const id = ctx.params.id;

  async function load() {
    let e;
    try { e = await api.get('/api/employees/' + id); } catch (ex) {
      ctx.header('Сотрудник');
      mount(ctx.root, h('div', { class: 'info-box' }, ex.message, ' ', h('a', { href: '#/employees' }, 'К списку сотрудников')));
      return;
    }
    ctx.header(e.full_name);
    const ld = e.load || {};
    const sched = e.schedule || [];
    const exc = e.exceptions || [];
    mount(ctx.root,
      h('div', { class: 'toolbar' },
        h('a', { href: '#/employees' }, '< К списку сотрудников'),
        admin ? [h('button', { class: 'btn sec right', onClick: () => employeeForm(ctx, ref, e, load) }, 'Изменить'),
          h('button', { class: 'btn sec', onClick: () => scheduleForm(e) }, 'График'),
          h('button', { class: 'btn sec', onClick: () => linksForm(e, 'services') }, 'Услуги'),
          h('button', { class: 'btn sec', onClick: () => linksForm(e, 'houses') }, 'Дома')] : null),
      h('div', { class: 'two-col' },
        h('div', null,
          kvRows([
            ['Должность:', asName(e.position)], ['Статус:', asName(e.status)], ['Тип графика:', asName(e.schedule_type)],
            ['Сейчас на смене:', e.on_shift_now ? 'да' : 'нет'],
            ['Телефон:', e.phone], ['E-mail:', e.email || '-'], ['Принят:', fmtDMY(e.hire_date)],
            e.user ? ['Учётная запись:', e.user.login + (e.user.is_active ? '' : ' (отключена)')] : null,
            ['Нагрузка:', `${ld.current ?? 0} из ${ld.max ?? ''}, открытых заявок: ${ld.open_requests ?? 0}`],
          ]),
          h('div', { class: 'sec-block' }, h('h2', null, 'Специализация (услуги)'),
            (e.services || []).length ? h('div', { class: 'chips' }, e.services.map((s) => h('span', { class: 'chip', title: s.name }, `${s.code} ${s.name}`))) : h('div', { class: 'muted' }, 'Услуги не привязаны')),
          h('div', { class: 'sec-block' }, h('h2', null, 'Закреплённые дома'),
            (e.houses || []).length ? h('div', { class: 'chips' }, e.houses.map((x) => h('span', { class: 'chip' }, x.address))) : h('div', { class: 'muted' }, 'Дома не привязаны'))),
        h('div', null,
          h('h2', { style: { fontSize: '18px', marginBottom: '12px' } }, 'График работы'),
          sched.length ? dataTable([
            { label: 'День', w: 110, render: (d) => d.weekday_name },
            { label: 'Смена', w: 120, render: (d) => SHIFT[d.shift_type] || d.shift_type },
            { label: 'Начало', render: (d) => d.start_time },
            { label: 'Конец', render: (d) => d.end_time },
            { label: 'Перерыв', render: (d) => (d.break_start ? `${d.break_start}-${d.break_end}` : '-') },
          ], sched, { className: 'sched' }) : h('div', { class: 'empty' }, 'График не задан'),
          exc.length ? h('div', { class: 'sec-block' }, h('h2', null, 'Исключения из графика'), dataTable([
            { label: 'Дата', w: 110, render: (x) => fmtDMY(x.date) },
            { label: 'Режим', render: (x) => (x.is_day_off ? 'выходной' : `${x.start_time}-${x.end_time}`) },
            { label: 'Причина', render: (x) => x.reason || '' },
          ], exc)) : null)));

    // ---- редактирование графика (админ)
    function scheduleForm(emp) {
      const byDay = Object.fromEntries((emp.schedule || []).map((d) => [d.weekday, d]));
      const names = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
      const rows = names.map((n, i) => {
        const d = byDay[i + 1];
        const on = checkbox(n, { checked: !!d });
        const shift = select(Object.entries(SHIFT).map(([v, l]) => ({ value: v, label: l })), d ? d.shift_type : 'day');
        const s = h('input', { type: 'time', value: d ? d.start_time : '09:00' });
        const en = h('input', { type: 'time', value: d ? d.end_time : '18:00' });
        const bs = h('input', { type: 'time', value: d && d.break_start ? d.break_start : '' });
        const be = h('input', { type: 'time', value: d && d.break_end ? d.break_end : '' });
        return { weekday: i + 1, on, shift, s, en, bs, be,
          el: h('div', { style: { display: 'grid', gridTemplateColumns: '130px 110px repeat(4, minmax(0, 1fr))', gap: '8px', marginBottom: '8px', alignItems: 'center' } }, on, shift, s, en, bs, be) };
      });
      const err = errorBox();
      const m = modal({
        title: 'График работы: ' + emp.short_name, wide: true,
        body: [h('div', { class: 'muted', style: { marginBottom: '8px', fontSize: '12px' } }, 'День, смена, начало, конец, перерыв с / по'), ...rows.map((r) => r.el), err],
        footer: [
          h('button', { class: 'btn', onClick: async (ev) => {
            const days = rows.filter((r) => r.on.querySelector('input').checked).map((r) => ({
              weekday: r.weekday, shift_type: r.shift.value, start_time: r.s.value, end_time: r.en.value,
              break_start: r.bs.value || null, break_end: r.be.value || null,
            }));
            ev.target.disabled = true;
            try { await api.put(`/api/employees/${emp.id}/schedule`, { days }); m.close(); toast('График сохранён', 'ok'); load(); } catch (ex) { showErr(err, ex); ev.target.disabled = false; }
          } }, 'Сохранить'),
          h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
        ],
      });
    }

    async function linksForm(emp, kind) {
      const isSvc = kind === 'services';
      let all = [];
      try { all = isSvc ? asList(await api.get('/api/services', { archived: 0 })) : asList(await api.get('/api/houses')); } catch (ex) { toast(ex.message, 'err'); return; }
      if (!isSvc && !all.length) all = ref.houses || [];
      const chosen = new Set((emp[kind] || []).map((x) => x.id));
      const list = h('div', { class: 'check-list' }, all.map((x) => h('label', null,
        h('input', { type: 'checkbox', value: x.id, checked: chosen.has(x.id) }), ' ', isSvc ? `${x.code} ${x.name}` : x.address)));
      const err = errorBox();
      const m = modal({
        title: isSvc ? 'Специализация: услуги' : 'Закреплённые дома', wide: true, body: [list, err],
        footer: [
          h('button', { class: 'btn', onClick: async (ev) => {
            const ids = [...list.querySelectorAll('input:checked')].map((i) => +i.value);
            ev.target.disabled = true;
            try { await api.put(`/api/employees/${emp.id}/${kind}`, isSvc ? { service_ids: ids } : { house_ids: ids }); m.close(); toast('Сохранено', 'ok'); load(); } catch (ex) { showErr(err, ex); ev.target.disabled = false; }
          } }, 'Сохранить'),
          h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
        ],
      });
    }
  }
  await load();
}

// ================================================================ клиенты
export async function clients(ctx) {
  ctx.header('Клиенты');
  const st = { q: ctx.query.q || '' };
  const search = h('input', { type: 'search', placeholder: 'Поиск по телефону или ФИО', value: st.q });
  const box = h('div');
  const err = errorBox();
  async function load() {
    ctx.setQuery(st);
    err.hidden = true;
    let items;
    try { items = asList(await api.get('/api/clients', st)); } catch (e) { showErr(err, e); return; }
    mount(box, dataTable([
      { label: 'ФИО', w: 260, render: (c) => c.full_name },
      { label: 'Телефон', w: 160, render: (c) => c.phone },
      { label: 'E-mail', w: 220, render: (c) => c.email || '' },
      { label: 'Статус', w: 170, render: (c) => asName(c.status) || c.status_name || '' },
      { label: 'Помещение', render: (c) => { const p = (c.premises || [])[0]; return p ? addrOf({ address: p.address }, p) + ((c.premises || []).length > 1 ? ` (+${c.premises.length - 1})` : '') : ''; } },
      { label: 'Заявок', w: 80, cls: 'num', render: (c) => c.requests_count ?? '' },
    ], items, { onRow: (c) => ctx.go('/clients/' + c.id), empty: 'Клиенты не найдены' }));
  }
  search.addEventListener('input', debounce(() => { st.q = search.value.trim(); load(); }, 300));
  mount(ctx.root, h('div', { class: 'toolbar' }, search), err, box);
  await load();
}

export async function client(ctx) {
  const ref = await ctx.ref();
  const id = ctx.params.id;
  async function load() {
    let c;
    try { c = await api.get('/api/clients/' + id); } catch (ex) {
      ctx.header('Клиент');
      mount(ctx.root, h('div', { class: 'info-box' }, ex.message, ' ', h('a', { href: '#/clients' }, 'К списку клиентов')));
      return;
    }
    ctx.header(c.full_name);
    const reqs = c.requests || [];
    const prem = c.premises || [];
    mount(ctx.root,
      h('div', { class: 'toolbar' }, h('a', { href: '#/clients' }, '< К списку клиентов'),
        h('button', { class: 'btn sec right', onClick: () => editClient(c) }, 'Изменить')),
      kvRows([
        ['Телефон:', c.phone], ['E-mail:', c.email || '-'], ['Статус:', asName(c.status) || c.status_name || '-'],
        ['Уведомления:', c.notify_consent ? 'согласен' : 'нет согласия'],
        prem.length ? ['Помещения:', prem.map((p) => addrOf(p.house || { address: p.address || p.house_address }, p)).join('; ')] : null,
      ]),
      h('div', { class: 'sec-block' }, h('h2', null, 'История обращений'),
        reqs.length ? dataTable([
          { label: '№ заявки', w: 130, render: (r) => r.number },
          { label: 'Дата', w: 110, render: (r) => fmtDMHM(r.received_at) },
          { label: 'Услуга', w: 260, render: (r) => (r.service && r.service.name) || r.service_name },
          { label: 'Исполнитель', w: 150, render: (r) => (r.assignee ? (r.assignee.short_name || shortFio(r.assignee.full_name)) : (r.assignee_name || 'не назначен')) },
          { label: 'Статус', w: 150, render: (r) => (r.status && r.status.name) || r.status_name },
          { label: 'Закрыта', w: 140, render: (r) => { const t = r.closed_at || (r.deadlines && r.deadlines.closed_at); return t ? fmtDMYHM(t) : '-'; } },
        ], reqs, { onRow: (r) => ctx.go('/requests/' + r.id) }) : h('div', { class: 'empty' }, 'Обращений пока не было')));
  }

  function editClient(c) {
    const f = {
      full_name: h('input', { type: 'text', value: c.full_name || '' }),
      phone: phoneInput({ value: c.phone || '' }),
      email: h('input', { type: 'email', value: c.email || '' }),
      status_code: select((ref.client_statuses || []).map((s) => ({ value: s.code, label: s.name })), c.status && c.status.code),
    };
    const consent = h('input', { type: 'checkbox', checked: !!c.notify_consent });
    const err = errorBox();
    const m = modal({
      title: 'Изменение клиента',
      body: [field('ФИО', f.full_name), field('Телефон', f.phone), field('E-mail', f.email), field('Статус', f.status_code),
        h('label', { class: 'chk' }, consent, ' согласие на уведомления'), err],
      footer: [
        h('button', { class: 'btn', onClick: async (ev) => {
          ev.target.disabled = true;
          try {
            await api.put('/api/clients/' + c.id, { ...splitFio(f.full_name.value), phone: f.phone.value, email: f.email.value.trim() || null, status_code: f.status_code.value, notify_consent: consent.checked });
            m.close(); toast('Сохранено', 'ok'); load();
          } catch (ex) { showErr(err, ex); ev.target.disabled = false; }
        } }, 'Сохранить'),
        h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
      ],
    });
  }
  await load();
}
