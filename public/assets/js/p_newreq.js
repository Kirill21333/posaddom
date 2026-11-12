// Новая заявка (макет 3_new_request): клиент по телефону, адрес, категория, услуга, фото «до», расчёт сроков
import * as api from './api.js';
import {
  h, mount, field, select, phoneInput, debounce, asList, fmtDMYHM, pluralize, toast, toastErr, confirmDialog,
  errorBox, showErr, shortFio, splitFio,
} from './ui.js';

const DRAFT_KEY = 'pd_draft_request';
const MAX_PHOTO = 10 * 1024 * 1024;

const loadDraft = () => { try { return JSON.parse(localStorage.getItem(DRAFT_KEY) || '{}') || {}; } catch (e) { return {}; } };
const saveDraft = (d) => { try { localStorage.setItem(DRAFT_KEY, JSON.stringify(d)); } catch (e) { /* noop */ } };
const clearDraft = () => { try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* noop */ } };

/** «30 минут», «2 часа», «24 часа», «1 рабочий день» по нормативу категории из справочника */
function normText(cat, kind) {
  if (!cat) return '';
  if (kind === 'arrive') {
    if (cat.response_minutes) {
      const m = +cat.response_minutes;
      if (m % 60 === 0) { const hh = m / 60; return `${hh} ${pluralize(hh, 'час', 'часа', 'часов')}`; }
      return `${m} ${pluralize(m, 'минута', 'минуты', 'минут')}`;
    }
    if (cat.response_work_days) { const d = +cat.response_work_days; return `${d} ${pluralize(d, 'рабочий день', 'рабочих дня', 'рабочих дней')}`; }
    return '';
  }
  if (cat.resolve_hours) { const hh = +cat.resolve_hours; return `${hh} ${pluralize(hh, 'час', 'часа', 'часов')}`; }
  return '';
}

export async function form(ctx) {
  ctx.header('Новая заявка');
  ctx.root.classList.add('page-form');
  const ref = await ctx.ref();
  const services = asList(await api.get('/api/services', { archived: 0 }));
  const draft = loadDraft();
  const st = { client: null, lookedUp: '', photos: [] };

  // ---- поля
  const phone = phoneInput({ id: 'f-phone', name: 'phone', value: draft.phone || '' });
  const phoneState = h('div', { class: 'phone-state' });
  const clName = h('input', { type: 'text', placeholder: 'Фамилия Имя Отчество', value: draft.client_name || '' });
  const clEmail = h('input', { type: 'email', placeholder: 'name@example.ru', value: draft.client_email || '' });
  const clStatus = select((ref.client_statuses || []).map((s) => ({ value: s.code, label: s.name })), draft.client_status);
  const clConsent = h('input', { type: 'checkbox', checked: draft.client_consent !== false });
  const newClientBox = h('div', { class: 'sub-form', hidden: true },
    h('h4', null, 'Новый клиент'),
    field('ФИО', clName), field('E-mail', clEmail), field('Статус', clStatus),
    h('label', { class: 'chk', style: { marginBottom: '12px' } }, clConsent, ' клиент согласен на уведомления (SMS, e-mail)'));

  const houseSel = select([{ value: '', label: 'Выберите дом' }, ...(ref.houses || []).map((x) => ({ value: x.id, label: x.address }))], draft.house || '');
  houseSel.id = 'f-house';
  const unit = h('input', { id: 'f-unit', type: 'text', placeholder: 'кв.', value: draft.unit || '', maxlength: 14 });
  const defaultCat = (ref.categories.find((c) => c.code === draft.category) || ref.categories[0] || {}).code;
  const radios = h('div', { class: 'radios' }, ref.categories.map((c) => h('label', { class: 'radio' },
    h('input', { type: 'radio', name: 'cat', value: c.code, checked: c.code === defaultCat }), c.name)));
  const svcSel = select([{ value: '', label: 'Выберите услугу' }, ...services.map((s) => ({
    value: s.id, label: `${s.code} ${s.name}` + (s.is_paid ? ' (платная)' : ''),
  }))], draft.service || '');
  const svcHint = h('div', { class: 'hint' });
  svcSel.id = 'f-service';
  const desc = h('textarea', { id: 'f-description', placeholder: 'Опишите проблему', maxlength: 2000 }, draft.description || '');
  desc.value = draft.description || '';
  const fileInp = h('input', { type: 'file', accept: 'image/jpeg,image/png', multiple: true, hidden: true });
  const photoRow = h('div', { class: 'photo-row' });
  const err = errorBox();
  const saveBtn = h('button', { id: 'f-save', class: 'btn save', type: 'submit' }, 'Сохранить');

  // ---- расчёт: блок «Рассчитывается автоматически»
  const calcBody = h('div');
  const dash = '—';
  function drawCalc(p, msg) {
    const catObj = ref.categories.find((c) => c.code === curCat());
    const line = (k, v, cls) => h('p', null, k + ': ', cls ? h('span', { class: cls }, v) : v);
    let assignee = dash;
    let acls = '';
    if (p) {
      if (p.assignee) assignee = `${p.assignee.short_name || shortFio(p.assignee.full_name)} (назначен автоматически)`;
      else { assignee = 'не назначен (заявка попадёт в очередь)'; acls = 'warn'; }
    }
    mount(calcBody,
      line('Номер заявки', p ? p.number : dash),
      line('Срок выезда', p ? fmtDMYHM(p.arrive_due) + (normText(catObj, 'arrive') ? ` (${normText(catObj, 'arrive')})` : '') : dash),
      line('Срок устранения', p ? fmtDMYHM(p.due_at) + (normText(catObj, 'resolve') ? ` (${normText(catObj, 'resolve')})` : '') : dash),
      line('Исполнитель', assignee, acls),
      msg ? h('p', { class: 'over' }, msg) : null,
      h('p', { class: 'calc-hint' }, 'Если исполнителя нет, заявка попадёт в очередь'));
  }
  const curCat = () => { const r = radios.querySelector('input:checked'); return r ? r.value : ''; };
  let previewSeq = 0;
  async function preview() {
    const category_code = curCat(); const house_id = houseSel.value; const service_id = svcSel.value;
    if (!category_code || !house_id || !service_id) { drawCalc(null); return; }
    const seq = ++previewSeq;
    try {
      const p = await api.post('/api/requests/preview', { category_code, house_id: +house_id, service_id: +service_id });
      if (seq === previewSeq) drawCalc(p);
    } catch (e) {
      if (seq === previewSeq) drawCalc(null, e.message);
    }
  }
  const previewSoon = debounce(preview, 200);
  drawCalc(null);

  // ---- клиент по телефону
  const digits = (v) => v.replace(/\D/g, '');
  async function lookup() {
    const d = digits(phone.value);
    if (d.length < 11) {
      st.client = null; st.lookedUp = ''; newClientBox.hidden = true;
      mount(phoneState);
      return;
    }
    if (st.lookedUp === d) return;
    st.lookedUp = d;
    try {
      const r = await api.get('/api/clients/lookup', { phone: phone.value });
      if (digits(phone.value) !== d) return;
      const c = r && (r.client !== undefined ? r.client : r);
      if (c && c.id) {
        st.client = c;
        newClientBox.hidden = true;
        mount(phoneState, h('span', { class: 'ok' }, 'клиент найден: ' + (c.full_name || c.name)));
        const pr = (c.premises && c.premises[0]) || c.premise;
        if (pr && !houseSel.value && pr.house_id) { houseSel.value = String(pr.house_id); if (!unit.value && pr.number) unit.value = pr.number; previewSoon(); }
      } else {
        st.client = null;
        newClientBox.hidden = false;
        mount(phoneState, h('span', { class: 'warn' }, 'клиент не найден: заполните данные нового клиента'));
      }
    } catch (e) {
      st.lookedUp = '';
      mount(phoneState, h('span', { class: 'over' }, e.message));
    }
  }
  phone.addEventListener('input', debounce(lookup, 400));
  phone.addEventListener('blur', lookup);

  // ---- фото «до»
  function drawPhotos() {
    mount(photoRow,
      st.photos.map((f, i) => h('div', { class: 'ph' },
        h('img', { src: f.url, alt: 'фото ' + (i + 1) }),
        h('button', { class: 'ph-x', type: 'button', title: 'Убрать', onClick: () => { URL.revokeObjectURL(f.url); st.photos.splice(i, 1); drawPhotos(); } }, '×'))),
      h('div', { class: 'ph add', title: 'Добавить фото', onClick: () => fileInp.click() }, '+'));
  }
  fileInp.addEventListener('change', () => {
    for (const f of fileInp.files) {
      if (!['image/jpeg', 'image/png'].includes(f.type)) { toast(`Файл «${f.name}» не принят: допустимы только JPEG и PNG`, 'err'); continue; }
      if (f.size > MAX_PHOTO) { toast(`Файл «${f.name}» больше 10 МБ`, 'err'); continue; }
      st.photos.push({ file: f, url: URL.createObjectURL(f) });
    }
    fileInp.value = '';
    drawPhotos();
  });
  drawPhotos();

  // ---- обновление подсказки по услуге и черновика
  function updateSvcHint() {
    const s = services.find((x) => String(x.id) === svcSel.value);
    mount(svcHint, s && s.is_paid ? `Платная услуга${s.price ? ': ' + s.price + ' руб.' : ''}. При закрытии заявки будет создано начисление.` : '');
  }
  const persist = debounce(() => saveDraft({
    phone: phone.value, house: houseSel.value, unit: unit.value, category: curCat(), service: svcSel.value, description: desc.value,
    client_name: clName.value, client_email: clEmail.value, client_status: clStatus.value, client_consent: clConsent.checked,
  }), 300);

  houseSel.addEventListener('change', previewSoon);
  svcSel.addEventListener('change', () => { updateSvcHint(); previewSoon(); });
  radios.addEventListener('change', previewSoon);
  const formEl = h('form', { novalidate: true });
  formEl.addEventListener('input', persist);
  formEl.addEventListener('change', persist);

  // ---- сохранение
  async function submit(e) {
    e.preventDefault();
    err.hidden = true;
    const category_code = curCat();
    const problems = [];
    if (digits(phone.value).length < 11) problems.push('Укажите телефон клиента полностью');
    else if (!st.client && clName.value.trim().split(/\s+/).length < 2) problems.push('Укажите фамилию и имя нового клиента');
    if (!houseSel.value) problems.push('Выберите дом');
    if (!category_code) problems.push('Выберите категорию');
    if (!svcSel.value) problems.push('Выберите услугу');
    if (!desc.value.trim()) problems.push('Опишите проблему');
    if (problems.length) { showErr(err, new Error(problems.join('. ') + '.')); return; }

    saveBtn.disabled = true;
    try {
      const house_id = +houseSel.value;
      const unitNum = unit.value.trim().replace(/^кв\.?\s*/i, '');
      let client = st.client;
      if (!client) {
        const body = {
          ...splitFio(clName.value), phone: phone.value, email: clEmail.value.trim() || null,
          status_code: clStatus.value, notify_consent: clConsent.checked,
        };
        if (unitNum || house_id) body.premise = { house_id, number: unitNum, type: unitNum && !/^\d/.test(unitNum) ? 'non_residential' : 'residential' };
        try {
          client = await api.post('/api/clients', body);
        } catch (ex) {
          const dup = ex.status === 409 && ex.body && (ex.body.duplicate || (ex.body.data && ex.body.data.duplicate));
          if (!dup) throw ex;
          const name = dup.full_name || (dup.client && dup.client.full_name) || '';
          const ok = await confirmDialog(`Клиент с таким телефоном и помещением уже есть в базе${name ? ': ' + name : ''}.\nСоздать ещё одну запись клиента?`, { title: 'Возможный дубль клиента', ok: 'Создать всё равно' });
          if (!ok) { saveBtn.disabled = false; return; }
          client = await api.post('/api/clients', { ...body, confirm_duplicate: true });
        }
      }
      const client_id = client.id || (client.client && client.client.id);

      let premise_id = null;
      if (unitNum) {
        const findPremise = async () => asList(await api.get(`/api/houses/${house_id}/premises`))
          .find((x) => String(x.number).toLowerCase() === unitNum.toLowerCase());
        let p = await findPremise();
        if (!p) {
          // нового помещения нет в справочнике: оно регистрируется вместе с привязкой к клиенту
          await api.put('/api/clients/' + client_id, { premise: { house_id, number: unitNum, type: /^\d/.test(unitNum) ? 'residential' : 'non_residential' } });
          p = await findPremise();
        }
        if (!p) throw new Error(`Помещение «${unitNum}» не найдено в справочнике дома. Проверьте номер или обратитесь к администратору.`);
        premise_id = p.id;
      }

      const channel = ref.channels.find((c) => c.code === 'phone') || ref.channels[0];
      const res = await api.post('/api/requests', {
        client_id, house_id, premise_id, category_code, service_id: +svcSel.value,
        channel_code: channel && channel.code, description: desc.value.trim(),
      });

      let failed = 0;
      for (const f of st.photos) {
        const fd = new FormData();
        fd.append('file', f.file);
        fd.append('stage', 'initial');
        try { await api.upload(`/api/requests/${res.id}/photos`, fd); } catch (ex) { failed++; toastErr(ex); }
      }
      clearDraft();
      toast(`Заявка ${res.number} зарегистрирована` + (res.assignee ? `, исполнитель: ${res.assignee.short_name || shortFio(res.assignee.full_name)}` : ', отправлена в очередь нераспределённых') + (failed ? `. Не загружено фото: ${failed}` : ''), 'ok');
      ctx.go('/requests/' + res.id);
    } catch (ex) {
      showErr(err, ex);
      saveBtn.disabled = false;
    }
  }
  formEl.addEventListener('submit', submit);

  mount(formEl,
    h('div', { class: 'nr-grid' },
      h('div', null,
        h('div', { class: 'fld fld-phone' }, h('label', null, 'Телефон клиента'), phone, phoneState),
        newClientBox,
        h('div', { class: 'fld' }, h('label', null, 'Адрес'), h('div', { class: 'nr-addr' }, houseSel, unit)),
        h('div', { class: 'fld' }, h('label', null, 'Категория'), radios),
        h('div', { class: 'fld fld-svc' }, h('label', null, 'Услуга'), svcSel, svcHint),
        h('div', { class: 'fld fld-desc' }, h('label', null, 'Описание проблемы'), desc),
        h('div', { class: 'fld' }, h('label', null, 'Фото'), photoRow, fileInp),
        err,
        h('div', { class: 'form-actions' }, saveBtn,
          h('button', { class: 'btn sec', type: 'button', onClick: () => ctx.go('/requests') }, 'Отмена'))),
      h('div', { class: 'calc' }, h('h3', null, 'Рассчитывается автоматически'), calcBody)));
  mount(ctx.root, formEl);
  updateSvcHint();
  if (digits(phone.value).length >= 11) lookup();
  preview();
}
