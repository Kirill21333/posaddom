// График и статистика сотрудника, уведомления
import * as api from './api.js';
import { h, mount, dataTable, asList, kvRows, fmtDMY, fmtDMYHM, fmtNum, errorBox, showErr, toast, toastErr } from './ui.js';
import { refreshUnread } from './app.js';

const SHIFT = { day: 'дневная', night: 'ночная', full: 'круглосуточная' };
const asName = (v) => (v && typeof v === 'object' ? v.name : v) || '';

export async function schedule(ctx) {
  ctx.header('График и статистика');
  const err = errorBox();
  let sch; let stats;
  try {
    [sch, stats] = await Promise.all([api.get('/api/me/schedule'), api.get('/api/me/stats')]);
  } catch (e) { showErr(err, e); mount(ctx.root, err); return; }
  const emp = sch.employee || {};
  const up = sch.upcoming || [];
  const exc = sch.exceptions || [];
  const bs = stats.by_status || {};
  const statBox = (n, label, cls) => h('div', { class: 'stat ' + (cls || '') }, h('div', { class: 'n' }, n ?? 0), h('div', { class: 'l' }, label));
  mount(ctx.root,
    kvRows([
      ['Сотрудник:', emp.full_name], ['Должность:', asName(emp.position)], ['Статус:', asName(emp.status)],
      ['График:', typeof emp.schedule_type === 'string' ? emp.schedule_type : asName(emp.schedule_type)],
      ['Сейчас на смене:', sch.on_shift_now ? 'да' : 'нет'],
    ]),
    h('div', { class: 'sec-block' }, h('h2', null, 'Статистика')),
    h('div', { class: 'cards' },
      statBox(`${stats.load ?? 0} из ${stats.max_load ?? ''}`, 'Нагрузка'),
      statBox(stats.open_requests, 'Открытых заявок'),
      statBox(stats.overdue_now, 'Просрочено', stats.overdue_now ? 'over' : ''),
      statBox(stats.completed_this_month, 'Выполнено в этом месяце')),
    h('div', { class: 'summary-line' },
      `Назначено: ${bs.assigned ?? 0}, выполняется: ${bs.in_progress ?? 0}, выполнено (ожидает закрытия): ${bs.done ?? 0}. `,
      `За период: заявок ${stats.total ?? 0}, выполнено ${stats.completed ?? 0}, в срок ${stats.in_time ?? 0}`,
      stats.in_time_percent !== null && stats.in_time_percent !== undefined ? ` (${fmtNum(stats.in_time_percent)} %)` : '',
      stats.avg_resolve_hours !== null && stats.avg_resolve_hours !== undefined ? `, среднее время устранения ${fmtNum(stats.avg_resolve_hours)} ч` : '', '.'),
    h('div', { class: 'sec-block' }, h('h2', null, 'Ближайшие дни'),
      up.length ? dataTable([
        { label: 'Дата', w: 120, render: (d) => fmtDMY(d.date) },
        { label: 'День', w: 130, render: (d) => d.weekday_name },
        { label: 'Смена', render: (d) => (d.working ? `${d.start_time}-${d.end_time} (${SHIFT[d.shift_type] || ''})` : 'выходной') },
        { label: 'Перерыв', render: (d) => (d.break_start ? `${d.break_start}-${d.break_end}` : '-') },
        { label: 'Примечание', render: (d) => (d.source === 'exception' ? (d.reason || 'исключение из графика') : '') },
      ], up, { className: 'sched' }) : h('div', { class: 'empty' }, 'График не задан')),
    exc.length ? h('div', { class: 'sec-block' }, h('h2', null, 'Исключения из графика'), dataTable([
      { label: 'Дата', w: 120, render: (x) => fmtDMY(x.date) },
      { label: 'Режим', render: (x) => (x.is_day_off ? 'выходной' : `${x.start_time}-${x.end_time}`) },
      { label: 'Причина', render: (x) => x.reason || '' },
    ], exc)) : null);
}

// ================================================================ уведомления
export async function notifications(ctx) {
  ctx.header('Уведомления');
  const box = h('div');
  const err = errorBox();
  async function load() {
    err.hidden = true;
    let d;
    try { d = await api.get('/api/notifications'); } catch (e) { showErr(err, e); return; }
    const items = asList(d);
    const unread = (n) => n.status !== 'read' && !n.is_read && !n.read_at;
    mount(box, items.length ? items.map((n) => h('div', { class: 'ntf-item' + (unread(n) ? ' unread' : ''), onClick: () => open(n) },
      h('div', null, n.title ? h('b', null, n.title + '. ') : null, n.message || n.body || n.text || ''),
      h('div', { class: 'ntf-time' }, fmtDMYHM(n.created_at), n.request_number ? ' | заявка ' + n.request_number : ''))) : h('div', { class: 'empty' }, 'Уведомлений нет'));
    refreshUnread();
  }
  async function open(n) {
    try { if (n.status !== 'read') await api.post(`/api/notifications/${n.id}/read`); } catch (e) { /* noop */ }
    if (n.request_id) ctx.go('/requests/' + n.request_id); else load();
  }
  mount(ctx.root,
    h('div', { class: 'toolbar' }, h('button', { class: 'btn sec', onClick: async () => { try { await api.post('/api/notifications/read-all'); toast('Все уведомления прочитаны', 'ok'); load(); } catch (e) { toastErr(e); } } }, 'Прочитать все')),
    err, box);
  await load();
}
