// UI-хелперы: построитель DOM (без innerHTML), даты, таймеры сроков, формы, таблицы, модальные окна
import { ApiError } from './api.js';

// ---------------------------------------------------------------- DOM
export function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  if (attrs) {
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
      else if (k === 'value') el.value = v;
      else if (k === 'checked' || k === 'disabled' || k === 'selected' || k === 'hidden' || k === 'required' || k === 'multiple') el[k] = !!v;
      else if (k === 'data') Object.assign(el.dataset, v);
      else el.setAttribute(k, v === true ? '' : v);
    }
  }
  append(el, children);
  return el;
}

function append(el, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
}

export function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }
export function mount(el, ...children) { clear(el); append(el, children); return el; }

// ---------------------------------------------------------------- словари
export const ROLE_LABEL = { dispatcher: 'диспетчер', employee: 'исполнитель', manager: 'руководитель', admin: 'администратор' };
const FINAL_CODES = ['done', 'closed', 'rejected', 'cancelled'];
export const isFinalStatus = (code) => FINAL_CODES.includes(code);

// ---------------------------------------------------------------- даты
// В ответах API время московское, без часового пояса: 'YYYY-MM-DD HH:MM:SS'
const MSK = 3 * 3600 * 1000;
const pad = (n) => String(n).padStart(2, '0');
function parts(s) {
  const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
  return m ? { y: +m[1], mo: +m[2], d: +m[3], h: +(m[4] || 0), mi: +(m[5] || 0), s: +(m[6] || 0) } : null;
}
/** Настоящий момент времени (мс) для московской строки */
export function ts(s) { const p = parts(s); return p ? Date.UTC(p.y, p.mo - 1, p.d, p.h, p.mi, p.s) - MSK : null; }
export const fmtDM = (s) => { const p = parts(s); return p ? `${pad(p.d)}.${pad(p.mo)}` : ''; };
export const fmtDMY = (s) => { const p = parts(s); return p ? `${pad(p.d)}.${pad(p.mo)}.${p.y}` : ''; };
export const fmtHM = (s) => { const p = parts(s); return p ? `${pad(p.h)}:${pad(p.mi)}` : ''; };
export const fmtDMHM = (s) => { const p = parts(s); return p ? `${pad(p.d)}.${pad(p.mo)} ${pad(p.h)}:${pad(p.mi)}` : ''; };
export const fmtDMYHM = (s) => { const p = parts(s); return p ? `${pad(p.d)}.${pad(p.mo)}.${p.y} ${pad(p.h)}:${pad(p.mi)}` : ''; };
export const dayKey = (s) => { const p = parts(s); return p ? `${p.y}-${pad(p.mo)}-${pad(p.d)}` : ''; };
export const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const nowMsk = () => new Date(Date.now() + MSK).toISOString().slice(0, 10);

export const pluralize = (n, a, b, c) => { n = Math.abs(n) % 100; const k = n % 10; return n > 10 && n < 20 ? c : k > 1 && k < 5 ? b : k === 1 ? a : c; };

/** Компактная длительность: «4 мин», «1 ч 40 мин», «21 ч», «2 дня» */
export function dur(min) {
  min = Math.max(1, Math.round(Math.abs(min)));
  if (min < 60) return `${min} мин`;
  if (min < 1440) { const hh = Math.floor(min / 60); const mm = min % 60; return mm ? `${hh} ч ${mm} мин` : `${hh} ч`; }
  const d = Math.floor(min / 1440);
  return `${d} ${pluralize(d, 'день', 'дня', 'дней')}`;
}

export const fmtNum = (v, digits = 1) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: digits }).format(+v || 0);
export function fmtPrice(v) {
  const n = +v;
  if (!isFinite(n)) return '-';
  return (Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',')) + ' руб.';
}

/** «Петрова Анна Сергеевна» -> {last_name, first_name, middle_name} */
export function splitFio(n) {
  const p = String(n || '').trim().split(/\s+/);
  return { last_name: p[0] || '', first_name: p[1] || '', middle_name: p.slice(2).join(' ') };
}

/** «Петрова Анна Сергеевна» -> «Петрова А.С.» */
export function shortFio(n) {
  if (!n) return '';
  const p = String(n).trim().split(/\s+/);
  if (p.length < 2 || /\./.test(n)) return String(n);
  return p[0] + ' ' + p.slice(1, 3).map((x) => x[0].toUpperCase() + '.').join('');
}

/** Адрес: «ул. Дружбы, 7, кв. 9» / «ул. Дружбы, 7, подвал» */
export function addrOf(house, premise) {
  const base = house && (house.address || house.name) ? (house.address || house.name) : '';
  const n = premise && (premise.number ?? premise.name);
  if (n === undefined || n === null || n === '') return base;
  return base + ', ' + (/^\d+[а-яa-z]?(\/\d+)?$/i.test(String(n)) ? 'кв. ' + n : n);
}

// ---------------------------------------------------------------- таймеры сроков
/** Какой срок показывать в таблице: до выезда (для новых) или до устранения */
export function deadlineOf(r) {
  const code = r.status && r.status.code;
  if (isFinalStatus(code)) return null;
  if (code === 'new' && r.arrive_due) return { due: r.arrive_due, kind: 'arrive' };
  return r.due_at ? { due: r.due_at, kind: 'due' } : null;
}

function timerLabel(dueTs, kind, mode) {
  const diffMin = (dueTs - Date.now()) / 60000;
  if (diffMin < 0) {
    // в таблице списка просрочка показывается укрупнённо («просрочено 2 ч»), в сводке подробно
    const m = -diffMin;
    return { text: 'просрочено ' + (mode === 'list' && m >= 60 && m < 1440 ? Math.floor(m / 60) + ' ч' : dur(m)), over: true };
  }
  const d = dur(Math.ceil(diffMin));
  if (mode === 'dash') return { text: (kind === 'arrive' ? 'выезд через ' : 'осталось ') + d, over: false };
  return { text: d, over: false };
}

/** span с остатком до произвольного срока (kind: arrive|due; mode: list|dash) */
export function timerFor(due, kind, mode = 'dash') {
  const t = ts(due);
  const span = h('span', { class: 'timer', data: { due: String(t), kind, mode } });
  const lab = timerLabel(t, kind, mode);
  span.textContent = lab.text;
  if (lab.over) span.classList.add('over');
  return span;
}

/** span, текст которого пересчитывается раз в минуту (refreshTimers) */
export function timerSpan(r, mode = 'list') {
  const dl = deadlineOf(r);
  if (!dl) return h('span', { class: 'muted' }, '-');
  const t = ts(dl.due);
  const span = h('span', { class: 'timer', data: { due: String(t), kind: dl.kind, mode } });
  const lab = timerLabel(t, dl.kind, mode);
  if (!lab.over && r.is_overdue) {
    span.textContent = 'просрочено ' + dur(r.overdue_minutes || 1);
    span.classList.add('over');
    delete span.dataset.due;
  } else {
    span.textContent = lab.text;
    if (lab.over) span.classList.add('over');
  }
  return span;
}

export function refreshTimers() {
  for (const el of document.querySelectorAll('.timer[data-due]')) {
    const lab = timerLabel(+el.dataset.due, el.dataset.kind, el.dataset.mode);
    if (el.textContent !== lab.text) el.textContent = lab.text;
    el.classList.toggle('over', lab.over);
  }
}
setInterval(refreshTimers, 60000);

// ---------------------------------------------------------------- сообщения
export function toast(msg, type) {
  const box = document.getElementById('toasts');
  if (!box) return;
  const t = h('div', { class: 'toast ' + (type || '') }, msg);
  box.append(t);
  setTimeout(() => t.remove(), type === 'err' ? 7000 : 3500);
}
export const errText = (e) => (e instanceof ApiError ? e.message : (e && e.message) || 'Произошла ошибка');
export const toastErr = (e) => toast(errText(e), 'err');

/** Выполняет действие; при ошибке показывает сообщение. Возвращает результат или undefined. */
export async function guard(fn, okMsg) {
  try {
    const r = await fn();
    if (okMsg) toast(okMsg, 'ok');
    return r === undefined ? true : r;
  } catch (e) {
    toastErr(e);
    return undefined;
  }
}

// ---------------------------------------------------------------- модальные окна
export function modal({ title, body, footer, wide, onClose }) {
  const close = () => { ov.remove(); document.removeEventListener('keydown', esc); if (onClose) onClose(); };
  const esc = (e) => { if (e.key === 'Escape') close(); };
  const ov = h('div', { class: 'overlay', onMousedown: (e) => { if (e.target === ov) close(); } },
    h('div', { class: 'modal' + (wide ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true' },
      h('h2', null, title),
      h('div', { class: 'modal-b' }, body),
      footer && footer.length ? h('div', { class: 'modal-f' }, footer) : null));
  document.addEventListener('keydown', esc);
  document.body.append(ov);
  const first = ov.querySelector('input:not([type=hidden]), textarea, select');
  if (first) first.focus();
  return { close, el: ov };
}

export function confirmDialog(message, { title = 'Подтверждение', ok = 'Подтвердить', body } = {}) {
  return new Promise((resolve) => {
    let done = false;
    const fin = (v) => { if (!done) { done = true; resolve(v); } };
    const m = modal({
      title, onClose: () => fin(false),
      body: [h('p', { class: 'pre' }, message), body],
      footer: [
        h('button', { class: 'btn', onClick: () => { fin(true); m.close(); } }, ok),
        h('button', { class: 'btn sec', onClick: () => { fin(false); m.close(); } }, 'Отмена'),
      ],
    });
  });
}

// ---------------------------------------------------------------- формы
export function field(label, control, hint) {
  return h('div', { class: 'fld' }, label ? h('label', null, label) : null, control, hint ? h('div', { class: 'hint' }, hint) : null);
}
/** options: [{value,label}] */
export function select(options, value, attrs) {
  const s = h('select', attrs, options.map((o) => h('option', { value: o.value }, o.label)));
  if (value !== undefined && value !== null) s.value = String(value);
  return s;
}
export const opts = (list, valueKey, labelFn) => list.map((x) => ({ value: x[valueKey], label: labelFn ? labelFn(x) : x.name }));
export function errorBox() { return h('div', { class: 'err-box', hidden: true }); }
export function showErr(box, e) { box.textContent = errText(e); box.hidden = false; }
export function checkbox(label, attrs) {
  return h('label', { class: 'chk' }, h('input', { type: 'checkbox', ...attrs }), ' ', label);
}
/** Ответ списка: массив или {items:[...]} */
export const asList = (d) => (Array.isArray(d) ? d : (d && d.items) || []);
export function debounce(fn, ms = 350) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

export function maskPhone(v) {
  let d = String(v || '').replace(/\D/g, '');
  if (d.startsWith('8')) d = '7' + d.slice(1);
  if (d && !d.startsWith('7')) d = '7' + d;
  d = d.slice(0, 11);
  if (!d) return '';
  let r = '+7';
  if (d.length > 1) r += ' (' + d.slice(1, 4);
  if (d.length >= 4) r += ')';
  if (d.length > 4) r += ' ' + d.slice(4, 7);
  if (d.length > 7) r += '-' + d.slice(7, 9);
  if (d.length > 9) r += '-' + d.slice(9, 11);
  return r;
}
export function phoneInput(attrs) {
  const i = h('input', { type: 'tel', placeholder: '+7 (___) ___-__-__', autocomplete: 'off', ...attrs });
  i.addEventListener('input', (e) => { if (!(e.inputType || '').startsWith('delete')) i.value = maskPhone(i.value); });
  return i;
}

// ---------------------------------------------------------------- таблицы
/** columns: [{label, w, render(row)|key, cls}] ; w - ширина в px */
export function dataTable(columns, rows, { onRow, rowClass, empty = 'Ничего не найдено', className } = {}) {
  if (!rows.length) return h('div', { class: 'empty' }, empty);
  return h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl ' + (className || '') },
    h('colgroup', null, columns.map((c) => h('col', c.w ? { style: { width: c.w + 'px' } } : null))),
    h('thead', null, h('tr', null, columns.map((c) => h('th', { class: c.cls || '' }, c.label)))),
    h('tbody', null, rows.map((r) => {
      const cells = columns.map((c) => {
        const v = typeof c.render === 'function' ? c.render(r) : r[c.key];
        const td = h('td', { class: c.cls || '' }, v === undefined || v === null || v === '' ? '' : v);
        if (typeof v === 'string' || typeof v === 'number') td.title = String(v);
        return td;
      });
      return h('tr', {
        class: (onRow ? 'click ' : '') + ((rowClass && rowClass(r)) || ''),
        onClick: onRow ? (e) => { if (!e.target.closest('a,button,input,select')) onRow(r); } : null,
      }, cells);
    }))));
}

export function pager({ page, per, total, onPage }) {
  const pages = Math.max(1, Math.ceil(total / per));
  if (pages <= 1) return null;
  return h('span', { class: 'pager' },
    h('button', { class: 'btn sec sm', disabled: page <= 1, onClick: () => onPage(page - 1) }, '< Назад'),
    ` стр. ${page} из ${pages} `,
    h('button', { class: 'btn sec sm', disabled: page >= pages, onClick: () => onPage(page + 1) }, 'Далее >'));
}

export function kvRows(rows) {
  return h('div', { class: 'kv' }, rows.filter(Boolean).map(([k, v]) => h('div', { class: 'kv-row' }, h('span', { class: 'kv-k' }, k), h('span', { class: 'kv-v' }, v))));
}
export function spinner() { return h('div', { class: 'loading' }, 'Загрузка...'); }
export function empty(text) { return h('div', { class: 'empty' }, text); }
