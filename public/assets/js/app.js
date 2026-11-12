// Точка входа SPA ПосадДом: состояние, hash-маршрутизация, оболочка (левое меню + шапка)
import * as api from './api.js';
import { h, mount, clear, spinner, toast, toastErr, ROLE_LABEL, shortFio, refreshTimers } from './ui.js';

export const state = { user: null, unread: 0 };
const app = document.getElementById('app');

// ------------------------------------------------------------ справочники (/api/ref), кэшируются
let refCache = null;
export async function getRef(force) {
  if (!refCache || force) refCache = await api.get('/api/ref');
  return refCache;
}

// ------------------------------------------------------------ меню по ролям
const startsWith = (...p) => (path) => p.some((x) => path === x || path.startsWith(x + '/'));
const REQ_LIST = (path) => path === '/requests' || (path.startsWith('/requests/') && path !== '/requests/new');
const NAV = {
  dispatcher: [
    { label: 'Заявки', href: '/requests', active: REQ_LIST },
    { label: 'Новая заявка', href: '/requests/new', active: startsWith('/requests/new') },
    { label: 'Услуги', href: '/services', active: startsWith('/services') },
    { label: 'Клиенты', href: '/clients', active: startsWith('/clients') },
    { label: 'Отчёты', href: '/reports', active: startsWith('/reports') },
  ],
  manager: [
    { label: 'Сводка', href: '/dashboard', active: startsWith('/dashboard') },
    { label: 'Заявки', href: '/requests', active: REQ_LIST },
    { label: 'Услуги', href: '/services', active: startsWith('/services') },
    { label: 'Сотрудники', href: '/employees', active: startsWith('/employees') },
    { label: 'Клиенты', href: '/clients', active: startsWith('/clients') },
    { label: 'Отчёты', href: '/reports', active: startsWith('/reports') },
  ],
  admin: [
    { label: 'Заявки', href: '/requests', active: REQ_LIST },
    { label: 'Услуги', href: '/services', active: startsWith('/services') },
    { label: 'Сотрудники', href: '/employees', active: startsWith('/employees') },
    { label: 'Клиенты', href: '/clients', active: startsWith('/clients') },
    { label: 'Отчёты', href: '/reports', active: startsWith('/reports') },
    { label: 'Пользователи', href: '/users', active: startsWith('/users') },
    { label: 'Правила назначения', href: '/rules', active: startsWith('/rules') },
    { label: 'Настройки', href: '/settings', active: startsWith('/settings') },
    { label: 'Журнал', href: '/audit', active: startsWith('/audit') },
  ],
  employee: [
    { label: 'Мои заявки', href: '/requests', active: REQ_LIST },
    { label: 'График и статистика', href: '/my', active: startsWith('/my') },
    { label: 'Уведомления', href: '/notifications', active: startsWith('/notifications'), badge: true },
  ],
};
export const navFor = (user) => NAV[user.role] || [];
export const homeFor = (user) => (user.role === 'manager' ? '/dashboard' : '/requests');
export const is = (...roles) => !!state.user && roles.includes(state.user.role);

// ------------------------------------------------------------ маршруты: [шаблон, модуль, функция, опции]
const ALL = ['dispatcher', 'employee', 'manager', 'admin'];
const STAFF = ['dispatcher', 'manager', 'admin'];
const ROUTES = [
  ['/login', 'p_auth', 'login', { layout: 'auth' }],
  ['/requests', 'p_requests', 'list', { roles: ALL }],
  ['/requests/new', 'p_newreq', 'form', { roles: ['dispatcher', 'admin'] }],
  ['/requests/:id', 'p_card', 'card', { roles: ALL }],
  ['/services', 'p_services', 'list', { roles: STAFF }],
  ['/dashboard', 'p_dashboard', 'page', { roles: ['manager', 'admin'] }],
  ['/employees', 'p_people', 'employees', { roles: STAFF }],
  ['/employees/:id', 'p_people', 'employee', { roles: STAFF }],
  ['/clients', 'p_people', 'clients', { roles: STAFF }],
  ['/clients/:id', 'p_people', 'client', { roles: STAFF }],
  ['/reports', 'p_reports', 'page', { roles: STAFF }],
  ['/users', 'p_admin', 'users', { roles: ['admin'] }],
  ['/rules', 'p_admin', 'rules', { roles: ['admin'] }],
  ['/settings', 'p_admin', 'settings', { roles: ['admin'] }],
  ['/audit', 'p_admin', 'audit', { roles: ['admin'] }],
  ['/notifications', 'p_my', 'notifications', { roles: ALL }],
  ['/my', 'p_my', 'schedule', { roles: ['employee'] }],
].map(([pattern, mod, fn, opt]) => ({
  re: new RegExp('^' + pattern.replace(/:(\w+)/g, '(?<$1>[^/]+)') + '$'), mod, fn, opt,
}));

export function parseHash() {
  const raw = location.hash.replace(/^#/, '') || '/';
  const i = raw.indexOf('?');
  const path = i < 0 ? raw : raw.slice(0, i);
  const q = i < 0 ? '' : raw.slice(i + 1);
  return { path: path.replace(/\/+$/, '') || '/', query: Object.fromEntries(new URLSearchParams(q)) };
}
export const go = (path) => { if (location.hash === '#' + path) window.dispatchEvent(new HashChangeEvent('hashchange')); else location.hash = path; };
/** Обновляет query в адресе без перерисовки страницы */
export function setQuery(obj) {
  const { path } = parseHash();
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(obj)) if (v !== '' && v !== null && v !== undefined) p.set(k, v);
  const s = p.toString();
  history.replaceState(null, '', '#' + path + (s ? '?' + s : ''));
}

let cleanup = [];
let shell = null;
let renderToken = 0;

async function render() {
  const token = ++renderToken;
  cleanup.forEach((f) => { try { f(); } catch (e) { /* noop */ } });
  cleanup = [];
  const { path, query } = parseHash();
  const user = state.user;

  if (path === '/') return go(user ? homeFor(user) : '/login');
  const route = ROUTES.find((r) => r.re.test(path));
  if (!route) return showMessage(user, 'Страница не найдена', 'Проверьте адрес или вернитесь к списку заявок.');
  const params = { ...(path.match(route.re).groups || {}) };
  const opt = route.opt;

  if (opt.layout !== 'auth' && !user) return go('/login');
  if (opt.layout === 'auth' && user) return go(homeFor(user));
  if (opt.roles && user && !opt.roles.includes(user.role)) {
    return showMessage(user, 'Нет доступа', 'У вашей роли нет прав на этот раздел.');
  }

  const root = h('div', { class: 'page' }, spinner());
  const ctx = {
    root, params, query, user, path,
    header: (title) => { if (shell) shell.setTitle(title); document.title = title + ' - ПосадДом'; },
    headerExtra: (node) => { if (shell) shell.setExtra(node); },
    ref: getRef, go, setQuery, reload: render,
    onLeave: (f) => cleanup.push(f),
  };
  if (opt.layout === 'auth') mount(app, root); else showShell(user, root);
  let mod;
  try {
    mod = await import(`./${route.mod}.js`);
    if (token !== renderToken) return;
    await mod[route.fn](ctx);
  } catch (e) {
    if (token !== renderToken) return;
    console.error(e);
    mount(root, h('div', { class: 'info-box' }, h('b', null, 'Не удалось загрузить страницу. '), e.message || String(e), ' ',
      h('button', { class: 'btn sec sm', onClick: render }, 'Повторить')));
  }
  if (token === renderToken) { window.scrollTo(0, 0); refreshTimers(); }
}

function showMessage(user, title, text) {
  const root = h('div', { class: 'page' }, h('div', { class: 'info-box' }, text, ' ', h('a', { href: '#/' }, 'Вернуться на главную')));
  if (user) { showShell(user, root); shell.setTitle(title); } else mount(app, root);
}

// ------------------------------------------------------------ оболочка
function showShell(user, root) {
  const { path } = parseHash();
  const nav = navFor(user);
  const side = h('aside', { class: 'side' },
    h('div', { class: 'brand' }, h('div', { class: 'brand-name' }, 'ПосадДом'), h('div', { class: 'brand-sub' }, 'управляющая компания')),
    h('nav', { class: 'nav' }, nav.map((it) => h('a', { href: '#' + it.href, class: it.active(path) ? 'active' : '' },
      it.label, it.badge && state.unread ? ` (${state.unread})` : ''))));
  const title = h('h1', null, '');
  const extra = h('div', { class: 'top-extra' });
  const roleText = user.role_name || ROLE_LABEL[user.role] || user.role;
  const notifLink = user.role === 'employee' ? null
    : h('a', { href: '#/notifications', class: 'top-notif', id: 'top-notif' }, 'Уведомления' + (state.unread ? ` (${state.unread})` : ''));
  mount(app, h('div', { class: 'shell' }, side, h('div', { class: 'main' },
    h('header', { class: 'top' },
      h('div', { class: 'top-left' }, title, extra),
      h('div', { class: 'top-right' }, notifLink, h('span', null, `${shortFio(user.name)} (${roleText})`), h('a', { href: '#/login', onClick: logout }, 'Выйти'))),
    root)));
  shell = {
    setTitle: (t) => { title.textContent = t || ''; },
    setExtra: (n) => { mount(extra, n); },
  };
}

async function logout(e) {
  if (e) e.preventDefault();
  try { await api.post('/api/auth/logout'); } catch (err) { /* noop */ }
  state.user = null; state.unread = 0; refCache = null;
  api.setCsrf('');
  go('/login');
}

// ------------------------------------------------------------ уведомления (счётчик непрочитанных)
export function countUnread(d) {
  const items = Array.isArray(d) ? d : (d && d.items) || [];
  if (d && typeof d.unread === 'number') return d.unread;
  return items.filter((n) => n.status !== 'read' && !n.is_read && !n.read_at).length;
}
export async function refreshUnread() {
  if (!state.user) return;
  try {
    const d = await api.get('/api/notifications');
    state.unread = countUnread(d);
    const a = document.getElementById('top-notif');
    if (a) a.textContent = 'Уведомления' + (state.unread ? ` (${state.unread})` : '');
    if (state.user.role === 'employee') {
      const link = document.querySelector('.nav a[href="#/notifications"]');
      if (link) link.textContent = 'Уведомления' + (state.unread ? ` (${state.unread})` : '');
    }
  } catch (e) { /* noop */ }
}
setInterval(refreshUnread, 60000);

// ------------------------------------------------------------ вход и загрузка
export async function loadMe() {
  try {
    const d = await api.get('/api/auth/me');
    if (d && d.csrf) api.setCsrf(d.csrf);
    state.user = d && d.user ? d.user : (d && d.id ? d : null);
  } catch (e) {
    if (e.status === 401) { state.user = null; return null; }
    throw e;
  }
  return state.user;
}
export function setUser(d) {
  if (d && d.csrf) api.setCsrf(d.csrf);
  state.user = d && d.user ? d.user : d;
  state.unread = 0;
  refreshUnread();
}

window.addEventListener('hashchange', render);
window.addEventListener('pd:unauth', () => {
  if (!state.user) return;
  state.user = null;
  toast('Сессия истекла. Войдите заново.', 'err');
  go('/login');
});

(async function init() {
  try {
    await loadMe();
  } catch (e) {
    mount(app, h('div', { class: 'info-box', style: { margin: '80px auto', maxWidth: '480px' } }, h('b', null, 'Сервер недоступен. '), e.message));
    return;
  }
  if (state.user) refreshUnread();
  render();
})();
