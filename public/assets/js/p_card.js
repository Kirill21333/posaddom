// Карточка заявки (макет 4_request_card): поля, фото, история, комментарии, действия по allowed_actions
import * as api from './api.js';
import {
  h, mount, field, select, modal, confirmDialog, toast, toastErr, errorBox, showErr, kvRows, asList, shortFio, addrOf,
  fmtDMYHM, fmtDMHM, fmtDM, fmtHM, dayKey, fmtPrice, timerFor, isFinalStatus,
} from './ui.js';
import { is } from './app.js';

const posName = (p) => (p && typeof p === 'object' ? p.name : p) || '';

export async function card(ctx) {
  ctx.root.classList.add('page-card');
  const ref = await ctx.ref();
  const id = ctx.params.id;
  const statusName = (code) => (ref.statuses.find((s) => s.code === code) || {}).name || code;
  const staff = ctx.user.role !== 'employee';

  async function load() {
    let r;
    try {
      r = await api.get('/api/requests/' + id);
    } catch (e) {
      ctx.header('Заявка');
      ctx.headerExtra(null);
      mount(ctx.root, h('div', { class: 'info-box' }, e.message, ' ', h('a', { href: '#/requests' }, 'К списку заявок')));
      return;
    }
    draw(r);
  }

  function draw(r) {
    const allowed = r.allowed_actions || [];
    const can = (a) => allowed.includes(a);
    const code = r.status && r.status.code;
    const final = isFinalStatus(code);
    ctx.header('Заявка ' + r.number);
    ctx.headerExtra(h('span', { class: 'pill' }, r.status ? r.status.name : ''));
    const dl = r.deadlines || {};
    const svc = r.service || {};
    const asg = r.assignee;
    const lastAssign = asList(r.assignments).slice(-1)[0];

    // ---- поля
    const arriveExtra = dl.arrived_at ? ` (выехал в ${fmtHM(dl.arrived_at)})`
      : (!final && r.arrive_due ? [' (', timerFor(dl.arrive_due || r.arrive_due, 'arrive'), ')'] : '');
    const dueExtra = !final && (dl.due_at || r.due_at) ? [' (', timerFor(dl.due_at || r.due_at, 'due'), ')'] : '';
    const rows = [
      ['Дата поступления:', [fmtDMYHM(r.received_at), r.channel && (r.channel.name || r.channel) ? h('span', { class: 'muted' }, ` (${String(r.channel.name || r.channel).toLowerCase()})`) : '']],
      ['Категория:', r.category && r.category.name],
      ['Клиент:', r.client ? [staff ? h('a', { class: 'plain', href: '#/clients/' + r.client.id }, r.client.full_name) : r.client.full_name, r.client.phone ? ', ' + r.client.phone : ''] : ''],
      ['Адрес:', addrOf(r.house, r.premise)],
      ['Услуга:', svc.name ? `${svc.code} ${svc.name} (${svc.is_paid ? 'платная' + (svc.price ? ', ' + fmtPrice(svc.price) : '') : 'бесплатная'})` : ''],
      ['Исполнитель:', asg
        ? [`${asg.full_name || asg.short_name}${posName(asg.position) ? ', ' + posName(asg.position) : ''}`,
          lastAssign && lastAssign.off_schedule ? h('span', { class: 'warn' }, ' (вызов вне графика)') : '']
        : h('span', { class: 'warn' }, r.manual_assign_required ? 'не назначен (в очереди нераспределённых)' : 'не назначен')],
      ['Срок выезда:', [fmtDMHM(dl.arrive_due || r.arrive_due), arriveExtra]],
      ['Срок устранения:', [fmtDMYHM(dl.due_at || r.due_at), dueExtra]],
      ['Описание:', r.description],
      dl.completed_at ? ['Выполнена:', fmtDMYHM(dl.completed_at)] : null,
      dl.closed_at ? ['Закрыта:', fmtDMYHM(dl.closed_at)] : null,
      svc.is_paid && svc.basis ? ['Основание:', svc.basis] : null,
      r.charge ? ['Начисление:', `${fmtPrice(r.charge.amount)}${r.charge.status === 'cancelled' ? ' (аннулировано)' : ''}`] : null,
    ];

    // ---- фото
    const photos = asList(r.photos);
    const finalCount = photos.filter((p) => p.stage === 'final').length;
    const cap = (p) => `${p.stage === 'final' ? 'после' : 'до'}, ${fmtHM(p.uploaded_at)}, ${shortFio(p.uploaded_by_name)}`;
    const photoNodes = photos.map((p) => h('div', { class: 'photo' },
      h('div', { class: 'pbox' }, h('img', { src: p.url || `/api/photos/${p.id}`, alt: p.file_name || 'фото', loading: 'lazy', onClick: () => window.open(p.url || `/api/photos/${p.id}`, '_blank') })),
      h('div', { class: 'cap' }, cap(p))));
    if (!finalCount) photoNodes.push(h('div', { class: 'photo' }, h('div', { class: 'pbox empty-box' }, '-'), h('div', { class: 'cap' }, 'после: пока нет')));
    const fileInp = h('input', { type: 'file', accept: 'image/jpeg,image/png', hidden: true });
    let stageUp = 'initial';
    fileInp.addEventListener('change', async () => {
      const f = fileInp.files[0];
      fileInp.value = '';
      if (!f) return;
      if (!['image/jpeg', 'image/png'].includes(f.type)) return toast('Допустимы только файлы JPEG и PNG', 'err');
      if (f.size > 10 * 1024 * 1024) return toast('Файл больше 10 МБ', 'err');
      const fd = new FormData();
      fd.append('file', f);
      fd.append('stage', stageUp);
      try { await api.upload(`/api/requests/${r.id}/photos`, fd); toast('Фото загружено', 'ok'); load(); } catch (e) { toastErr(e); }
    });
    const pick = (stage) => { stageUp = stage; fileInp.click(); };

    // ---- история и комментарии
    let prevDay = '';
    const history = asList(r.history).map((x) => {
      const dk = dayKey(x.at);
      const when = dk !== prevDay ? fmtDMHM(x.at) : fmtHM(x.at);
      prevDay = dk;
      const who = x.user_name ? shortFio(x.user_name) : '';
      const extra = [who, x.comment].filter(Boolean).join(', ');
      return h('div', null, h('span', { class: 'hist-time' }, when), '  ', x.status_name || statusName(x.status), extra ? ` (${extra})` : '');
    });
    const comments = asList(r.comments).map((c) => {
      const same = dayKey(c.created_at) === dayKey(r.received_at);
      return h('div', null, `${shortFio(c.author_name)} ${same ? fmtHM(c.created_at) : fmtDMHM(c.created_at)}: ${c.text}`,
        c.is_additional_contact ? h('span', { class: 'muted' }, ' (дополнительное обращение клиента)') : '');
    });

    const additional = !can('comment') && can('comment_additional');
    const cInput = h('input', { id: 'comment-text', type: 'text', placeholder: additional ? 'Дополнительное обращение клиента' : 'Добавить комментарий', maxlength: 2000 });
    const sendBtn = h('button', { id: 'comment-send', class: 'btn', type: 'submit' }, 'Отправить');
    const cForm = (can('comment') || additional) ? h('form', { class: 'comm-form', onSubmit: async (e) => {
      e.preventDefault();
      const text = cInput.value.trim();
      if (!text) return toast('Введите текст комментария', 'err');
      sendBtn.disabled = true;
      try {
        await api.post(`/api/requests/${r.id}/comments`, { text, is_additional_contact: additional });
        load();
      } catch (ex) { toastErr(ex); sendBtn.disabled = false; }
    } }, cInput, sendBtn) : null;

    // ---- кнопки действий
    const otherStatuses = allowed.filter((a) => a.startsWith('status:')).map((a) => a.slice(7))
      .filter((c) => c !== 'closed' && c !== 'cancelled');
    const actions = h('div', { class: 'card-actions' },
      h('div', { class: 'card-btns' },
        otherStatuses.length ? h('button', { id: 'btn-status', class: 'btn', onClick: () => changeStatus(r, otherStatuses) }, 'Изменить статус') : null,
        can('status:closed') ? h('button', { id: 'btn-close', class: 'btn', onClick: () => closeRequest(r) }, 'Закрыть заявку') : null,
        can('assign') ? h('button', { id: 'btn-assign', class: 'btn sec', onClick: () => reassign(r) }, 'Переназначить') : null),
      h('div', { class: 'card-links' },
        can('edit') ? h('button', { class: 'link', onClick: () => edit(r) }, 'Изменить данные заявки') : null,
        can('status:cancelled') ? h('button', { class: 'link', onClick: () => changeStatus(r, ['cancelled'], 'Отмена заявки') }, 'Отменить заявку') : null));

    mount(ctx.root, h('div', { class: 'card-grid' },
      h('div', { class: 'card-left' },
        kvRows(rows),
        h('div', { class: 'sec-h' }, 'Фотографии'),
        h('div', { class: 'photos' }, photoNodes),
        can('photo') ? h('div', { class: 'photo-btns' },
          h('button', { class: 'btn sec sm', onClick: () => pick('initial') }, 'Добавить фото «до»'),
          h('button', { class: 'btn sec sm', onClick: () => pick('final') }, 'Добавить фото «после»'), fileInp) : null,
        code === 'done' && !finalCount && can('status:closed') ? h('div', { class: 'chk-note' }, 'Для закрытия заявки нужно загрузить хотя бы одно фото «после».') : null),
      h('div', null,
        actions,
        h('div', { class: 'right-sec' }, h('div', { class: 'sec-h' }, 'История статусов'), h('div', { class: 'hist' }, history)),
        h('div', { class: 'right-sec' }, h('div', { class: 'sec-h' }, 'Комментарии'), h('div', { class: 'comm' }, comments.length ? comments : h('div', { class: 'muted' }, 'Комментариев пока нет')),
          cForm,
          additional ? h('div', { class: 'chk-note' }, 'Заявка закрыта: можно добавить только дополнительное обращение клиента.') : null))));
  }

  // ---------------------------------------------------------- действия
  function changeStatus(r, codes, title = 'Изменение статуса') {
    const sel = select(codes.map((c) => ({ value: c, label: statusName(c) })), codes[0]);
    const comment = h('textarea', { placeholder: 'Комментарий (необязательно)', style: { height: '80px' } });
    const err = errorBox();
    const needReason = (c) => c === 'cancelled' || c === 'rejected';
    const m = modal({
      title,
      body: [codes.length > 1 ? field('Новый статус', sel) : h('p', { style: { marginBottom: '14px' } }, `Новый статус: ${statusName(codes[0])}`),
        field('Комментарий', comment), err],
      footer: [
        h('button', { class: 'btn', onClick: async (e) => {
          const code = sel.value;
          if (needReason(code) && !comment.value.trim()) return showErr(err, new Error('Укажите причину в комментарии'));
          e.target.disabled = true;
          try {
            await api.post(`/api/requests/${r.id}/status`, { status: code, comment: comment.value.trim() || undefined });
            m.close(); toast('Статус изменён: ' + statusName(code), 'ok'); load();
          } catch (ex) { showErr(err, ex); e.target.disabled = false; }
        } }, 'Сохранить'),
        h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
      ],
    });
  }

  async function closeRequest(r) {
    const paid = r.service && r.service.is_paid;
    const comment = h('textarea', { placeholder: 'Комментарий (необязательно)', style: { height: '70px' } });
    const err = errorBox();
    const m = modal({
      title: 'Закрытие заявки ' + r.number,
      body: [h('p', { style: { marginBottom: '14px' } }, 'После закрытия заявку нельзя редактировать, можно добавить только дополнительное обращение клиента.'
        + (paid ? ` Услуга платная: будет создано начисление${r.service.price ? ' ' + fmtPrice(r.service.price) : ''}.` : '')),
      field('Комментарий', comment), err],
      footer: [
        h('button', { class: 'btn', onClick: async (e) => {
          e.target.disabled = true;
          try {
            await api.post(`/api/requests/${r.id}/status`, { status: 'closed', comment: comment.value.trim() || undefined });
            m.close(); toast('Заявка закрыта', 'ok'); load();
          } catch (ex) { showErr(err, ex); e.target.disabled = false; }
        } }, 'Закрыть заявку'),
        h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
      ],
    });
  }

  /** Кандидат -> {id, name, position, load, max, off} независимо от формы ответа */
  const normCand = (c) => {
    const e = c.employee || c;
    return {
      id: c.employee_id ?? e.id ?? c.id,
      name: e.full_name || e.short_name || c.full_name || c.name || '',
      short: e.short_name || shortFio(e.full_name || c.full_name || c.name),
      position: posName(e.position || c.position),
      load: c.load ?? e.load,
      max: c.max_load ?? e.max_load,
      off: !!(c.off_schedule),
    };
  };

  async function reassign(r) {
    let cands = [];
    let loadErr = null;
    try { cands = asList(await api.get(`/api/requests/${r.id}/candidates`)).map(normCand); } catch (e) { loadErr = e; }
    const current = r.assignee ? r.assignee.id : null;
    const err = errorBox();
    if (loadErr) { err.textContent = loadErr.message; err.hidden = false; }
    const reason = h('input', { type: 'text', placeholder: 'Причина переназначения (необязательно)' });
    const list = h('div');
    const choice = [{ id: '', short: 'Автоматически', position: 'система подберёт по нагрузке', auto: true }, ...cands];
    for (const c of choice) {
      list.append(h('label', { class: 'cand' },
        h('input', { type: 'radio', name: 'cand', value: c.id, checked: c.auto }),
        h('span', null, h('b', null, c.short || c.name), c.position ? ', ' + c.position : '',
          c.load !== undefined && c.load !== null ? ` - нагрузка ${c.load}${c.max ? ' из ' + c.max : ''}` : '',
          c.id && String(c.id) === String(current) ? ' (текущий)' : '',
          c.off ? h('span', { class: 'warn' }, ' - вне графика') : '')));
    }
    if (!cands.length && !loadErr) list.append(h('div', { class: 'muted', style: { padding: '8px 0' } }, 'Свободных кандидатов нет: можно запустить автоподбор или выбрать позже.'));
    const m = modal({
      title: 'Переназначение заявки',
      wide: true,
      body: [h('p', { style: { marginBottom: '12px' } }, 'Выберите исполнителя из подходящих кандидатов (по услуге, дому, графику и нагрузке):'), list,
        h('div', { style: { marginTop: '14px' } }, field('Причина', reason)), err],
      footer: [
        h('button', { class: 'btn', onClick: async (e) => {
          const v = list.querySelector('input:checked').value;
          e.target.disabled = true;
          try {
            await api.post(`/api/requests/${r.id}/assign`, { employee_id: v ? +v : null, reason: reason.value.trim() || undefined });
            m.close(); toast('Исполнитель назначен', 'ok'); load();
          } catch (ex) { showErr(err, ex); e.target.disabled = false; }
        } }, 'Назначить'),
        h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
      ],
    });
  }

  async function edit(r) {
    let services = [];
    try { services = asList(await api.get('/api/services', { archived: 0 })); } catch (e) { /* noop */ }
    const desc = h('textarea', null);
    desc.value = r.description || '';
    const svcSel = select(services.map((s) => ({ value: s.id, label: `${s.code} ${s.name}` })), r.service && r.service.id);
    const catSel = select(ref.categories.map((c) => ({ value: c.code, label: c.name })), r.category && r.category.code);
    const err = errorBox();
    const m = modal({
      title: 'Изменение заявки ' + r.number,
      body: [field('Категория', catSel), field('Услуга', svcSel), field('Описание', desc), err],
      footer: [
        h('button', { class: 'btn', onClick: async (e) => {
          e.target.disabled = true;
          try {
            await api.put(`/api/requests/${r.id}`, { category_code: catSel.value, service_id: +svcSel.value, description: desc.value.trim() });
            m.close(); toast('Заявка изменена', 'ok'); load();
          } catch (ex) { showErr(err, ex); e.target.disabled = false; }
        } }, 'Сохранить'),
        h('button', { class: 'btn sec', onClick: () => m.close() }, 'Отмена'),
      ],
    });
  }

  await load();
}
