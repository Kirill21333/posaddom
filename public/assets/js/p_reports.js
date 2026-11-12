// Отчёты: выбор вида, фильтры, просмотр и выгрузка (PDF / XLSX / CSV)
import * as api from './api.js';
import { h, mount, select, dataTable, asList, isoDate, errorBox, showErr, fmtDMYHM, fmtDMY, fmtNum } from './ui.js';

const KINDS = [
  ['requests', 'По заявкам за период'],
  ['executors', 'По исполнителям'],
  ['paid', 'По платным услугам'],
  ['overdue', 'По просроченным заявкам'],
  ['clients', 'По обращениям клиентов'],
];

// подписи итоговых показателей (summary приходит с техническими ключами)
const SUM_LABELS = {
  total: 'Всего заявок', closed: 'Закрыто', overdue: 'Просрочено', still_open: 'Ещё не выполнены', max_overdue_minutes: 'Макс. просрочка, мин',
  avg_overdue_minutes: 'Средняя просрочка, мин', clients: 'Клиентов', requests: 'Заявок', avg_per_client: 'В среднем на клиента',
  completed: 'Выполнено', in_time: 'В срок', late: 'С просрочкой', amount: 'Сумма, руб.', total_amount: 'Сумма, руб.', sum: 'Сумма, руб.',
  count: 'Количество', avg_resolve_hours: 'Среднее время устранения, ч', avg_hours: 'Среднее время устранения, ч', executors: 'Исполнителей',
  paid: 'Платных', charges: 'Начислений', accrued: 'Начислено, руб.', cancelled: 'Аннулировано, руб.',
};
const sumLabel = (k) => SUM_LABELS[k] || (k.startsWith('cat_') ? 'Категория «' + k.slice(4) + '»' : k);
const sumValue = (v) => (typeof v === 'number' ? fmtNum(v, 2) : (v && typeof v === 'object' ? JSON.stringify(v) : String(v ?? '')));
/** Значения-даты из отчёта показываем в формате дд.мм.гггг чч:мм */
function cell(v) {
  if (v === null || v === undefined) return '';
  const s = String(v);
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(s)) return fmtDMYHM(s);
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return fmtDMY(s);
  return s;
}

export async function page(ctx) {
  ctx.header('Отчёты');
  const ref = await ctx.ref();
  const now = new Date();
  const q = ctx.query;
  // доступные роли виды отчётов отдаёт сервер (GET /api/reports); запасной вариант - полный список
  let kinds = KINDS;
  try {
    const t = asList(await api.get('/api/reports'));
    if (t.length) kinds = t.map((x) => [x.type, x.title]);
  } catch (e) { /* noop */ }
  const st = {
    kind: kinds.some((k) => k[0] === q.kind) ? q.kind : kinds[0][0],
    from: q.from || isoDate(new Date(now.getFullYear(), now.getMonth(), 1)),
    to: q.to || isoDate(now),
    category: q.category || '', status: q.status || '', service: q.service || '', employee: q.employee || '', house: q.house || '',
  };
  let services = []; let people = [];
  try { services = asList(await api.get('/api/services', { archived: 'all' })); } catch (e) { /* noop */ }
  try { people = asList(await api.get('/api/employees')); } catch (e) { /* noop */ }

  const kindSel = select(kinds.map(([v, l]) => ({ value: v, label: l })), st.kind);
  const from = h('input', { type: 'date', value: st.from });
  const to = h('input', { type: 'date', value: st.to });
  const cat = select([{ value: '', label: 'Все категории' }, ...ref.categories.map((c) => ({ value: c.code, label: c.name }))], st.category);
  const stat = select([{ value: '', label: 'Все статусы' }, ...ref.statuses.map((s) => ({ value: s.code, label: s.name }))], st.status);
  const svc = select([{ value: '', label: 'Все услуги' }, ...services.map((s) => ({ value: s.id, label: `${s.code} ${s.name}` }))], st.service);
  const emp = select([{ value: '', label: 'Все исполнители' }, ...people.map((p) => ({ value: p.id, label: p.short_name || p.full_name }))], st.employee);
  const house = select([{ value: '', label: 'Все объекты' }, ...(ref.houses || []).map((x) => ({ value: x.id, label: x.address }))], st.house);
  const out = h('div', { class: 'sec-block' });
  const err = errorBox();

  const params = () => ({
    from: from.value, to: to.value, category: cat.value, status: stat.value, service: svc.value, employee: emp.value, house: house.value,
  });

  async function show() {
    err.hidden = true;
    ctx.setQuery({ kind: kindSel.value, ...params() });
    mount(out, h('div', { class: 'loading' }, 'Формирование отчёта...'));
    try {
      const d = await api.get('/api/reports/' + kindSel.value, { ...params(), format: 'json' });
      const cols = d.columns || [];
      const rows = d.rows || [];
      const sum = d.summary || {};
      const sumKeys = Object.keys(sum);
      mount(out,
        sumKeys.length ? h('div', { class: 'chips', style: { marginBottom: '14px' } }, sumKeys.map((k) => h('span', { class: 'chip' }, `${sumLabel(k)}: ${sumValue(sum[k])}`))) : null,
        rows.length ? dataTable(cols.map((c) => ({ label: c.title, render: (r) => cell(r[c.key]) })), rows, { className: 'auto' })
          : h('div', { class: 'empty' }, 'За выбранный период данных нет'));
    } catch (e) { mount(out); showErr(err, e); }
  }

  function exportAs(format) {
    const u = api.url('/api/reports/' + kindSel.value, { ...params(), format });
    if (format === 'pdf') { window.open(u, '_blank'); return; }
    const a = h('a', { href: u, download: '' });
    document.body.append(a);
    a.click();
    a.remove();
  }

  mount(ctx.root,
    h('div', { class: 'toolbar' }, kindSel, h('label', null, 'с ', from), h('label', null, 'по ', to)),
    h('div', { class: 'toolbar' }, cat, stat, svc, emp, house),
    h('div', { class: 'toolbar' },
      h('button', { class: 'btn', onClick: show }, 'Показать'),
      h('button', { class: 'btn sec', onClick: () => exportAs('pdf') }, 'PDF'),
      h('button', { class: 'btn sec', onClick: () => exportAs('xlsx') }, 'XLSX'),
      h('button', { class: 'btn sec', onClick: () => exportAs('csv') }, 'CSV')),
    err, out);
  await show();
}
