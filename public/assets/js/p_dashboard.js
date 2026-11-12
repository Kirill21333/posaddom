// Сводка руководителя (макет 6_dashboard)
import * as api from './api.js';
import { h, mount, select, dataTable, addrOf, timerSpan, fmtNum, errorBox, showErr, shortFio } from './ui.js';

const PERIODS = [['today', 'сегодня'], ['week', 'неделю'], ['month', 'месяц'], ['quarter', 'квартал']];

export async function page(ctx) {
  ctx.header('Сводка');
  const ref = await ctx.ref();
  const q = ctx.query;
  const st = { period: PERIODS.some((p) => p[0] === q.period) ? q.period : 'month', house: q.house || '' };

  const periodSel = select(PERIODS.map(([v, l]) => ({ value: v, label: 'За ' + l })), st.period);
  const houseSel = select([{ value: '', label: 'Все дома' }, ...(ref.houses || []).map((x) => ({ value: x.id, label: x.address }))], st.house);
  ctx.headerExtra(h('div', { class: 'dash-filters' }, periodSel, houseSel));
  const body = h('div', { class: 'dash' });
  const err = errorBox();

  async function load() {
    ctx.setQuery({ period: st.period, house: st.house });
    err.hidden = true;
    let d;
    try {
      d = await api.get('/api/dashboard', { period: st.period, house: st.house, limit: 8 });
    } catch (e) { showErr(err, e); mount(body); return; }
    const periodWord = (PERIODS.find((p) => p[0] === st.period) || [])[1] || 'месяц';
    const stat = (n, label, cls) => h('div', { class: 'stat ' + (cls || '') }, h('div', { class: 'n' }, n ?? 0), h('div', { class: 'l' }, label));
    const list = d.overdue_list || [];
    const loads = d.workload || [];
    const sla = d.sla || {};
    const pct = (v) => (v === null || v === undefined ? 'нет данных' : fmtNum(v, 1) + ' %');

    mount(body,
      h('div', { class: 'cards' },
        stat(d.new_today, 'Новые за сегодня'),
        stat(d.in_work, 'В работе'),
        stat(d.overdue, 'Просрочено', d.overdue ? 'over' : ''),
        stat(d.unassigned, 'Не назначены', d.unassigned ? 'warn' : '')),
      h('h2', null, 'Просроченные и под угрозой'),
      list.length ? dataTable([
        { label: '№ заявки', w: 130, render: (r) => r.number },
        { label: 'Адрес', w: 300, render: (r) => addrOf(r.house, r.premise) },
        { label: 'Исполнитель', w: 170, render: (r) => (r.assignee ? (r.assignee.short_name || shortFio(r.assignee.full_name)) : 'не назначен') },
        { label: 'До срока', w: 170, render: (r) => timerSpan(r, 'dash') },
        { label: 'Статус', w: 160, render: (r) => r.status && r.status.name },
      ], list, { onRow: (r) => ctx.go('/requests/' + r.id) }) : h('div', { class: 'empty' }, 'Просроченных заявок и заявок под угрозой нет'),
      h('h2', { class: 'second' }, 'Загрузка сотрудников'),
      loads.length ? loads.map((w) => {
        const e = w.employee;
        const name = typeof e === 'string' ? e : (e && (e.short_name || shortFio(e.full_name))) || w.name || '';
        const max = +w.max_load || 9;
        const pctw = Math.min(100, Math.round((+w.load || 0) / max * 100));
        return h('div', { class: 'load-row' }, h('div', { class: 'load-name' }, name),
          h('div', { class: 'load-bar' }, h('i', { class: pctw >= 85 ? 'high' : '', style: { width: pctw + '%' } })),
          h('div', { class: 'load-cnt' }, `${w.load} из ${max}`));
      }) : h('div', { class: 'muted' }, 'Нет данных о загрузке'),
      h('div', { class: 'sla' }, `Заявки в срок за ${periodWord}: аварийные ${pct(sla.emergency)}, срочные ${pct(sla.urgent)}, плановые ${pct(sla.planned)}`));
  }

  periodSel.addEventListener('change', () => { st.period = periodSel.value; load(); });
  houseSel.addEventListener('change', () => { st.house = houseSel.value; load(); });
  mount(ctx.root, err, body);
  await load();
}
