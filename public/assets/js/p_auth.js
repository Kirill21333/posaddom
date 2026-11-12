// Страница входа (макет 1_login)
import * as api from './api.js';
import { h, mount, errorBox, showErr } from './ui.js';
import { setUser, homeFor } from './app.js';

export async function login(ctx) {
  document.title = 'Вход - ПосадДом';
  const err = errorBox();
  const hintBox = h('div', { class: 'login-forgot-text', hidden: true },
    'Для сброса пароля обратитесь к администратору системы: он выдаст временный пароль.');
  const loginInp = h('input', { type: 'text', id: 'login', name: 'login', placeholder: 'логин', autocomplete: 'username', required: true });
  const passInp = h('input', { type: 'password', id: 'password', name: 'password', placeholder: '••••••', autocomplete: 'current-password', required: true });
  const btn = h('button', { class: 'btn login-btn', type: 'submit' }, 'Войти');
  const form = h('form', { class: 'login-card', onSubmit: async (e) => {
    e.preventDefault();
    err.hidden = true;
    if (!loginInp.value.trim() || !passInp.value) {
      showErr(err, new Error('Введите логин и пароль'));
      return;
    }
    btn.disabled = true;
    try {
      const d = await api.post('/api/auth/login', { login: loginInp.value.trim(), password: passInp.value });
      setUser(d);
      ctx.go(homeFor(d.user || d));
    } catch (ex) {
      showErr(err, ex);
      passInp.value = '';
      passInp.focus();
    } finally {
      btn.disabled = false;
    }
  } },
  h('h2', null, 'Вход в систему'),
  h('label', null, 'Логин'), loginInp,
  h('label', null, 'Пароль'), passInp,
  err,
  btn,
  h('a', { class: 'login-forgot', href: '#/login', onClick: (e) => { e.preventDefault(); hintBox.hidden = !hintBox.hidden; } }, 'Забыли пароль?'),
  hintBox);
  mount(ctx.root,
    h('div', { class: 'login-brand' }, 'ПосадДом'),
    form,
    h('div', { class: 'login-note' }, 'После 5 неверных попыток вход блокируется на 15 минут'));
  ctx.root.className = 'page login-page';
  loginInp.focus();
}
