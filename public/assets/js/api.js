// Клиент REST API ПосадДом: все ответы {ok:true,data} или {ok:false,error}
let csrf = '';
export const setCsrf = (v) => { csrf = v || ''; };

export class ApiError extends Error {
  constructor(message, status, body) {
    super(message);
    this.status = status;
    this.body = body || {};
    this.fields = (body && (body.fields || body.errors)) || {};
  }
}

const STATUS_TEXT = {
  400: 'Некорректный запрос',
  401: 'Сессия истекла. Войдите заново',
  403: 'Недостаточно прав для этого действия',
  404: 'Запись не найдена',
  409: 'Действие конфликтует с текущими данными',
  422: 'Проверьте правильность заполнения полей',
};

function qs(params) {
  if (!params) return '';
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') p.set(k, v);
  }
  const s = p.toString();
  return s ? '?' + s : '';
}

async function request(method, path, body, isForm) {
  const headers = { Accept: 'application/json' };
  if (csrf) headers['X-CSRF-Token'] = csrf;
  const init = { method, headers, credentials: 'same-origin' };
  if (body !== undefined && body !== null) {
    if (isForm) {
      init.body = body;
    } else {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
  }
  let res;
  try {
    res = await fetch(path, init);
  } catch (e) {
    throw new ApiError('Нет связи с сервером. Проверьте подключение и повторите попытку.', 0);
  }
  const ct = res.headers.get('content-type') || '';
  let data = null;
  if (ct.includes('application/json')) {
    try { data = await res.json(); } catch (e) { data = null; }
  }
  if (!res.ok || (data && data.ok === false)) {
    let msg = data && data.error;
    if (!msg) msg = res.status >= 500 ? 'Внутренняя ошибка сервера. Попробуйте позже.' : (STATUS_TEXT[res.status] || `Ошибка ${res.status}`);
    if (res.status === 401 && !path.startsWith('/api/auth/login')) {
      window.dispatchEvent(new CustomEvent('pd:unauth'));
    }
    throw new ApiError(msg, res.status, data);
  }
  if (data && Object.prototype.hasOwnProperty.call(data, 'data')) return data.data;
  return data;
}

export const get = (path, params) => request('GET', path + qs(params));
export const post = (path, body) => request('POST', path, body ?? {});
export const put = (path, body) => request('PUT', path, body ?? {});
export const del = (path) => request('DELETE', path);
export const upload = (path, formData) => request('POST', path, formData, true);
export const url = (path, params) => path + qs(params);
