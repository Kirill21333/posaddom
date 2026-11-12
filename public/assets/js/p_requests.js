// Список заявок (макет 2_requests_list): вкладки, фильтры, таблица со сроками
import * as api from './api.js';
import { h, mount, clear, select, debounce, dataTable, pager, fmtDMHM, addrOf, timerSpan, toastErr, errorBox, showErr } from './ui.js';
import { is } from './app.js';

const PER = 20;
const TABS = [
  ['all', 'Все', 'all'],
  ['new', 'Новые', 'new'],
  ['queue', 'Очередь нераспределённых', 'queue'],
  ['in_work', 'В работе', 'in_work'],
  ['overdue', 'Просроченные', 'overdue'],
  ['closed', 'Закрытые', 'closed'],
];

/** Параметры API для вкладки */
function tabParams(tab) {
  switch (tab) {
    case 'new': return { status: 'new,accepted' }; // «Новые» = новая + принята в работу
    case 'queue': return { queue: 1 };
    case 'in_work': return { status: 'assigned,in_progress' }; // «В работе»
    case 'overdue': return { overdue: 1 };
    case 'closed': return { status: 'closed', sort: 'received' };
    default: return {};
  }
}

export async function list(ctx) {
  const employee = ctx.user.role === 'employee';
  ctx.header(employee ? 'Мои заявки' : 'Список заявок');
  const ref = await ctx.ref();
  const q = ctx.query;
  const st = {
    tab: TABS.some((t) => t[0] === q.tab) ? q.tab : 'all',
    status: q.status || '', category: q.category || '', employee: q.employee || '',
    q: q.q || '', page: +q.page || 1,
  };

  // справочник исполнителей для фильтра (только руководящим ролям)
  let people = [];
  if (!employee) {
    try {
      const d = await api.get('/api/employees');
      people = Array.isArray(d) ? d : (d.items || []);
    } catch (e) { people = []; }
  }

  const tabsEl = h('div', { class: 'tabs' });
  const tableBox = h('div', { class: 'table-box' });
  const info = h('div', { class: 'under-table' });
  const errBox = errorBox();
  let counts = {};

  const statusSel = select([{ value: '', label: 'Статус' }, ...ref.statuses.map((s) => ({ value: s.code, label: s.name }))], st.status);
  const catSel = select([{ value: '', label: 'Категория' }, ...ref.categories.map((c) => ({ value: c.code, label: c.name }))], st.category);
  const empSel = employee ? null : select([{ value: '', label: 'Исполнитель' },
    { value: 'none', label: 'Не назначен' }, ...people.map((p) => ({ value: p.id, label: p.short_name || p.full_name || p.name }))], st.employee);
  const search = h('input', { type: 'search', class: 'f-search', placeholder: 'Поиск по номеру', value: st.q });

  const sync = () => ctx.setQuery({ tab: st.tab === 'all' ? '' : st.tab, status: st.status, category: st.category, employee: st.employee, q: st.q, page: st.page > 1 ? st.page : '' });

  function drawTabs() {
    mount(tabsEl, TABS.filter(([key]) => !(employee && (key === 'new' || key === 'queue'))).map(([key, label, ckey]) => {
      const n = counts[ckey];
      return h('a', {
        href: '#', class: 'tab' + (st.tab === key ? ' active' : '') + (key === 'overdue' && n ? ' red' : ''),
        onClick: (e) => { e.preventDefault(); st.tab = key; st.status = ''; statusSel.value = ''; st.page = 1; load(); },
      }, label + (n !== undefined ? ` ${n}` : ''));
    }));
  }

  async function load() {
    sync();
    drawTabs();
    errBox.hidden = true;
    const params = { ...tabParams(st.tab), per_page: PER, page: st.page };
    if (st.status) params.status = st.status;
    if (st.category) params.category = st.category;
    if (st.employee) params.employee = st.employee;
    if (st.q) params.q = st.q;
    if (!params.sort) params.sort = 'deadline';
    let d;
    try {
      d = await api.get('/api/requests', params);
    } catch (e) {
      showErr(errBox, e);
      mount(tableBox);
      mount(info);
      return;
    }
    counts = d.counts || counts;
    drawTabs();
    const items = d.items || [];
    mount(tableBox, dataTable([
      { label: '№ заявки', w: 125, render: (r) => r.number },
      { label: 'Дата', w: 100, render: (r) => fmtDMHM(r.received_at) },
      { label: 'Категория', w: 105, render: (r) => r.category && r.category.name },
      { label: 'Адрес', w: 235, render: (r) => addrOf(r.house, r.premise) },
      { label: 'Услуга', w: 185, render: (r) => r.service && r.service.name },
      { label: 'Исполнитель', w: 120, render: (r) => (r.assignee ? (r.assignee.short_name || r.assignee.full_name) : h('span', { class: 'warn' }, 'не назначен')) },
      { label: 'Статус', w: 160, render: (r) => r.status && r.status.name },
      { label: 'Срок', w: 130, render: (r) => timerSpan(r) },
    ], items, { onRow: (r) => ctx.go('/requests/' + r.id), empty: 'Заявок по заданным условиям нет' }));
    mount(info, `Показано ${items.length} из ${d.total ?? items.length} заявок`,
      pager({ page: st.page, per: PER, total: d.total || 0, onPage: (p) => { st.page = p; load(); } }));
  }

  const reset = () => { st.page = 1; load(); };
  statusSel.addEventListener('change', () => { st.status = statusSel.value; if (st.status) st.tab = 'all'; reset(); });
  catSel.addEventListener('change', () => { st.category = catSel.value; reset(); });
  if (empSel) empSel.addEventListener('change', () => { st.employee = empSel.value; reset(); });
  search.addEventListener('input', debounce(() => { st.q = search.value.trim(); reset(); }, 350));

  mount(ctx.root,
    tabsEl,
    h('div', { class: 'list-head' },
      statusSel, catSel, empSel, search,
      is('dispatcher', 'admin') ? h('a', { class: 'btn list-add', href: '#/requests/new' }, '+ Создать заявку') : null),
    errBox, tableBox, info);
  ctx.root.classList.add('page-list');
  await load();
}
