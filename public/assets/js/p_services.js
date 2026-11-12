// Справочник услуг (макет 5_services)
import * as api from './api.js';
import {
  h, mount, field, select, modal, confirmDialog, toast, toastErr, errorBox, showErr, debounce, dataTable, asList,
  fmtNum, fmtPrice, kvRows, fmtDMYHM,
} from './ui.js';
import { is } from './app.js';

/** «Минимальный перечень услуг и работ ... (ПП РФ № 290)» -> «Мин. перечень (ПП №290)» */
function shortBasis(t) {
  if (!t) return '-';
  if (/^Заявка собственника/i.test(t)) return 'Заявка собственника';
  return String(t).replace(/^Минимальный перечень[^(]*\(ПП[^№]*№\s*(\d+)\)\s*$/i, 'Мин. перечень (ПП №$1)');
}

/** Категории услуг в порядке дерева: [{id, label}] */
export function categoryOptions(cats) {
  const byParent = {};
  for (const c of cats) (byParent[c.parent_id || 0] ||= []).push(c);
  const out = [];
  const walk = (pid, depth) => {
    for (const c of (byParent[pid] || [])) {
      out.push({ value: c.id, label: (depth ? '  '.repeat(depth) + '- ' : '') + c.name });
      walk(c.id, depth + 1);
    }
  };
  walk(0, 0);
  return out;
}

export async function list(ctx) {
  ctx.header('Справочник услуг');
  const ref = await ctx.ref();
  const admin = is('admin');
  const q = ctx.query;
  const st = { q: q.q || '', cat: q.cat || '', paid: q.paid || '', archived: q.archived === '1' };

  const search = h('input', { type: 'search', class: 's-search', placeholder: 'Поиск по коду или названию', value: st.q });
  const catSel = select([{ value: '', label: 'Категория' }, ...categoryOptions(ref.service_categories || [])], st.cat);
  catSel.className = 's-cat';
  const paidSel = select([{ value: '', label: 'Платность' }, { value: '0', label: 'Бесплатные' }, { value: '1', label: 'Платные' }], st.paid);
  paidSel.className = 's-paid';
  const archChk = h('input', { type: 'checkbox', checked: st.archived });
  const box = h('div', { class: 'table-box tight' });
  const err = errorBox();
  let rows = [];

  async function load() {
    ctx.setQuery({ q: st.q, cat: st.cat, paid: st.paid, archived: st.archived ? 1 : '' });
    err.hidden = true;
    try {
      const d = await api.get('/api/services', { q: st.q, category_id: st.cat, paid: st.paid, archived: st.archived ? 'all' : 0 });
      rows = asList(d);
    } catch (e) { showErr(err, e); return; }
    mount(box, dataTable([
      { label: 'Код', w: 90, render: (s) => s.code },
      { label: 'Название', w: 290, render: (s) => s.name + (s.is_archived ? ' (архив)' : '') },
      { label: 'Категория', w: 190, render: (s) => s.category_name || (s.category && s.category.name) },
      { label: 'Ед.', w: 65, render: (s) => s.unit },
      { label: 'Срок, ч', w: 75, render: (s) => fmtNum(s.norm_hours) },
      { label: 'Платность', w: 105, render: (s) => (s.is_paid ? h('span', { class: 'warn' }, 'платная') : 'бесплатная') },
      { label: 'Цена', w: 100, render: (s) => (s.is_paid && s.price ? fmtPrice(s.price) : '-') },
      { label: 'Основание', w: 237, render: (s) => { const t = s.is_paid ? s.basis : (s.norm_document || s.basis); const el = h('span', null, shortBasis(t)); el.title = t || ''; return el; } },
    ], rows, { className: 'r40', rowClass: (s) => (s.is_archived ? 'muted' : ''), onRow: (s) => open(s), empty: 'Услуги не найдены' }));
  }

  const dload = debounce(load, 300);
  search.addEventListener('input', () => { st.q = search.value.trim(); dload(); });
  catSel.addEventListener('change', () => { st.cat = catSel.value; load(); });
  paidSel.addEventListener('change', () => { st.paid = paidSel.value; load(); });
  archChk.addEventListener('change', () => { st.archived = archChk.checked; load(); });

  mount(ctx.root,
    h('div', { class: 'filters' }, search, catSel, paidSel,
      h('label', { class: 'chk' }, archChk, ' показывать архивные'),
      admin ? h('button', { class: 'btn list-add', onClick: () => open(null) }, 'Добавить услугу') : null),
    err, box,
    h('div', { class: 'note' }, 'Услугу, по которой уже есть заявки, удалить нельзя, её можно только отправить в архив.'));

  // ---------------------------------------------------------- карточка / форма услуги
  function open(s) {
    if (!admin) return view(s);
    const isNew = !s;
    const v = s || { is_paid: 0 };
    const f = {
      code: h('input', { type: 'text', value: v.code || '', maxlength: 20 }),
      name: h('input', { type: 'text', value: v.name || '', maxlength: 200 }),
      category_id: select([{ value: '', label: 'Выберите категорию' }, ...categoryOptions(ref.service_categories || [])], v.category_id || ''),
      unit: h('input', { type: 'text', value: v.unit || '', maxlength: 20, placeholder: 'выезд, шт., м2' }),
      norm_hours: h('input', { type: 'number', step: '0.5', min: '0', value: v.norm_hours ?? '' }),
      price: h('input', { type: 'number', step: '0.01', min: '0', value: v.price ?? '' }),
      basis: h('input', { type: 'text', value: (v.is_paid ? v.basis : v.norm_document) || '', maxlength: 255, placeholder: 'по умолчанию подставляется стандартное основание' }),
      description: h('textarea', { style: { height: '70px' } }),
    };
    f.description.value = v.description || '';
    const paid = h('input', { type: 'checkbox', checked: !!v.is_paid });
    const syncPaid = () => { f.price.disabled = !paid.checked; if (!paid.checked) f.price.value = ''; };
    paid.addEventListener('change', syncPaid);
    syncPaid();
    const e = errorBox();
    const body = h('div', null,
      h('div', { style: { display: 'grid', gridTemplateColumns: '1fr 2fr', gap: '12px' } }, field('Код', f.code), field('Наименование', f.name)),
      field('Категория', f.category_id),
      h('div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' } }, field('Единица измерения', f.unit), field('Нормативный срок, ч', f.norm_hours)),
      h('div', { class: 'fld' }, h('label', { class: 'chk' }, paid, ' платная услуга')),
      field('Стоимость, руб.', f.price),
      field('Основание / нормативный документ', f.basis),
      field('Описание', f.description), e);

    const collect = () => ({
      code: f.code.value.trim(), name: f.name.value.trim(), category_id: f.category_id.value ? +f.category_id.value : null,
      unit: f.unit.value.trim(), norm_hours: f.norm_hours.value, is_paid: paid.checked ? 1 : 0,
      price: paid.checked ? f.price.value : null,
      [paid.checked ? 'basis' : 'norm_document']: f.basis.value.trim(),
      description: f.description.value.trim(),
    });
    const fail = (ex) => {
      for (const el of Object.values(f)) el.classList.remove('invalid');
      for (const k of Object.keys(ex.fields || {})) if (f[k]) f[k].style.borderColor = '#D32F2F';
      showErr(e, ex);
    };
    const footer = [
      h('button', { class: 'btn', onClick: async (ev) => {
        ev.target.disabled = true;
        try {
          if (isNew) await api.post('/api/services', collect()); else await api.put('/api/services/' + s.id, collect());
          m.close(); toast(isNew ? 'Услуга добавлена' : 'Изменения сохранены', 'ok'); load();
        } catch (ex) { fail(ex); ev.target.disabled = false; }
      } }, 'Сохранить'),
      h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
    ];
    if (!isNew) {
      footer.push(h('button', { class: 'btn sec', style: { marginLeft: 'auto' }, onClick: async () => {
        try {
          await api.post(`/api/services/${s.id}/${s.is_archived ? 'restore' : 'archive'}`);
          m.close(); toast(s.is_archived ? 'Услуга восстановлена из архива' : 'Услуга отправлена в архив', 'ok'); load();
        } catch (ex) { showErr(e, ex); }
      } }, s.is_archived ? 'Из архива' : 'В архив'));
      footer.push(h('button', { class: 'btn danger', onClick: async () => {
        if (!(await confirmDialog(`Удалить услугу «${s.code} ${s.name}»? Это возможно только если по ней нет заявок.`, { ok: 'Удалить' }))) return;
        try { await api.del('/api/services/' + s.id); m.close(); toast('Услуга удалена', 'ok'); load(); } catch (ex) { showErr(e, ex); }
      } }, 'Удалить'));
      const hist = h('div', { class: 'sec-block', style: { marginTop: '18px' } });
      body.append(hist);
      api.get(`/api/services/${s.id}/changes`).then((d) => {
        const items = asList(d);
        mount(hist, h('div', { class: 'sec-h' }, 'История изменений'),
          items.length ? dataTable([
            { label: 'Дата', w: 110, render: (c) => fmtDMYHM(c.changed_at) },
            { label: 'Поле', w: 120, render: (c) => c.field_label || c.field },
            { label: 'Было', render: (c) => c.old_value ?? '-' },
            { label: 'Стало', render: (c) => c.new_value ?? '-' },
            { label: 'Кто', w: 100, render: (c) => c.user_name },
          ], items, { className: 'wrap' }) : h('div', { class: 'muted' }, 'Изменений нет'));
      }).catch(() => {});
    }
    const m = modal({ title: isNew ? 'Новая услуга' : `Услуга ${s.code}`, wide: true, body, footer });
  }

  function view(s) {
    modal({
      title: `${s.code} ${s.name}`, wide: true,
      body: kvRows([
        ['Категория:', s.category_name], ['Единица:', s.unit], ['Срок, ч:', fmtNum(s.norm_hours)],
        ['Платность:', s.is_paid ? 'платная, ' + fmtPrice(s.price) : 'бесплатная'],
        ['Основание:', s.is_paid ? s.basis : s.norm_document], s.description ? ['Описание:', s.description] : null,
        s.is_archived ? ['Статус:', 'в архиве'] : null,
      ]),
      footer: [h('button', { class: 'btn sec', onClick: (ev) => ev.target.closest('.overlay').remove() }, 'Закрыть')],
    });
  }

  await load();
}
