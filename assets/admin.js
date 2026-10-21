/* MyAduan admin portal. Hash-routed, no build step, no dependencies. Talks only to the myaduan/v1 REST API. */
(() => {
  'use strict';

  const cfg = JSON.parse(document.getElementById('myaduan-config').textContent);
  if (cfg.login) {
    // Logged out: go to login and come back to the same route (the part after # is not sent to the server).
    const back = cfg.portal + location.hash;
    location.replace(cfg.login + (cfg.login.includes('?') ? '&' : '?') + 'redirect_to=' + encodeURIComponent(back));
    return;
  }
  const app = document.getElementById('app');
  const state = { me: null, catalog: null, cats: new Map(), duns: new Map(), areas: new Map(), lastList: '#/kes' };

  const ROLE = { superadmin: 'Super Admin', officer: 'Pegawai', viewer: 'Pemerhati', none: 'Tiada peranan' };
  const ROLE_HELP = {
    superadmin: 'Nampak semua aduan, kelaskan, tugaskan, sahkan penutupan dan urus pengguna.',
    officer: 'Nampak aduan dalam kategori yang dibenarkan dan bertindak atas kes yang ditugaskan kepadanya.',
    viewer: 'Lihat angka ringkasan dan dashboard sahaja. Tidak nampak butiran pengadu.',
  };
  const EVENT = {
    CIPTA: 'Aduan diterima', CLASSIFY: 'Dikelaskan', ASSIGN: 'Ditugaskan', ACCEPT: 'Tugasan diterima', ACTION: 'Tindakan direkod',
    COMMENT: 'Catatan dalaman', REQUEST_CLOSE: 'Mohon penutupan', APPROVE_CLOSE: 'Disahkan & ditutup', RETURN: 'Dikembalikan untuk tindakan',
    REOPEN: 'Dibuka semula', PUBLISH: 'Mesej awam dikemas kini',
  };
  const FIELD = {
    title: 'Tajuk', category_code: 'Kategori', dun_slug: 'DUN', area_id: 'Taman/kampung', priority: 'Keutamaan', owner_id: 'Pegawai',
    next_action: 'Tindakan seterusnya', followup_at: 'Tarikh susulan', closure_reason: 'Sebab penutupan', resolution: 'Penyelesaian',
    public_draft: 'Draf mesej awam', public_message: 'Mesej awam', gambar: 'Gambar',
  };
  const FLAGS = { open: 'Masih terbuka', not_accepted: 'Belum diterima pegawai', overdue: 'Lewat tarikh susulan' };

  /* ---------- helpers ---------- */

  /** Tiny element builder: h('div', {class: 'x'}, 'text', child). Text is always set safely. */
  function h(tag, attrs, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === false || v == null) continue;
      if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (k === 'value') el.value = v;
      else if (v === true) el.setAttribute(k, '');
      else el.setAttribute(k, v);
    }
    for (const c of children.flat(Infinity)) {
      if (c == null || c === false) continue;
      el.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return el;
  }

  function newKey() {
    const b = new Uint8Array(32);
    crypto.getRandomValues(b);
    return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  }

  const MY = 'Asia/Kuala_Lumpur';
  function formatDate(iso, withTime = true) {
    if (!iso) return '';
    const opts = { timeZone: MY, day: '2-digit', month: 'short', year: 'numeric' };
    if (withTime) Object.assign(opts, { hour: '2-digit', minute: '2-digit' });
    return new Intl.DateTimeFormat('ms-MY', opts).format(new Date(iso));
  }
  /** ISO time → "YYYY-MM-DDTHH:MM" in Malaysia time, for datetime-local inputs. */
  function toLocalInput(iso) {
    if (!iso) return '';
    const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
      timeZone: MY, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(new Date(iso)).map((x) => [x.type, x.value]));
    return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
  }
  const formatIc = (ic) => (/^\d{12}$/.test(ic || '') ? `${ic.slice(0, 6)}-${ic.slice(6, 8)}-${ic.slice(8)}` : ic);
  const slug = (s) => String(s || '').toLowerCase().replace(/[^a-z]+/g, '-');

  function statusBadge(status) { return h('span', { class: 'badge st-' + slug(status) }, status); }
  function priorityBadge(p) { return p && p !== 'BIASA' ? h('span', { class: 'badge pr-' + slug(p) }, p) : null; }
  function catName(code) { return code ? (state.cats.get(code)?.name || code) : null; }
  function dunName(s) { return s ? (state.duns.get(s)?.name || s) : null; }
  function officerName(id) {
    if (!id) return null;
    if (id === state.me.id) return state.me.name;
    return state.catalog.officers.find((o) => o.id === id)?.name || 'Pengguna #' + id;
  }

  let toastTimer;
  function toast(msg, kind = 'ok') {
    let el = document.getElementById('toast');
    if (!el) { el = h('div', { id: 'toast', role: 'status' }); document.body.append(el); }
    el.className = 'show ' + kind;
    el.textContent = msg;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = ''; }, 4000);
  }

  /* ---------- API ---------- */

  function apiUrl(path, params) {
    const u = new URL(cfg.api + path, location.href);
    for (const [k, v] of Object.entries(params || {})) if (v !== '' && v != null) u.searchParams.set(k, v);
    return u;
  }
  function fileUrl(id) { return apiUrl('/admin/files/' + id, { _wpnonce: cfg.nonce }).toString(); }

  async function api(path, { method = 'GET', params, body } = {}) {
    let res;
    try {
      res = await fetch(apiUrl(path, params), {
        method,
        credentials: 'same-origin',
        headers: Object.assign({ 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' }, body ? { 'Content-Type': 'application/json' } : {}),
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch {
      throw Object.assign(new Error('Tiada sambungan internet. Cuba lagi.'), { status: 0 });
    }
    let data = null;
    try { data = await res.json(); } catch { /* empty or not JSON */ }
    if (!res.ok) {
      if (data?.code === 'rest_cookie_invalid_nonce' || res.status === 401) sessionExpired();
      throw Object.assign(new Error(data?.message || `Ralat sistem (${res.status}).`), { status: res.status, code: data?.code, fields: data?.data?.fields });
    }
    return data;
  }

  function sessionExpired() {
    if (document.getElementById('expired')) return;
    document.body.append(h('div', { id: 'expired', role: 'alert' },
      h('p', {}, 'Sesi anda telah tamat. Muat semula halaman untuk log masuk semula.'),
      h('button', { class: 'btn', type: 'button', onclick: () => location.reload() }, 'Muat semula')));
  }

  /* ---------- shell & routing ---------- */

  function parseHash() {
    const raw = location.hash.replace(/^#/, '') || '/kes';
    const [path, qs] = raw.split('?');
    return { parts: path.split('/').filter(Boolean), query: Object.fromEntries(new URLSearchParams(qs || '')) };
  }
  function hashFor(path, query) {
    const qs = new URLSearchParams(Object.entries(query || {}).filter(([, v]) => v !== '' && v != null)).toString();
    return '#' + path + (qs ? '?' + qs : '');
  }

  let view;
  function shell() {
    const me = state.me;
    const links = [];
    if (me.can.work || me.can.manage) links.push(['#/kes', 'Senarai kes', 'kes']);
    else links.push(['#/ringkasan', 'Ringkasan', 'ringkasan']);
    if (me.can.manage) links.push(['#/pengguna', 'Pengguna', 'pengguna']);
    view = h('main', { id: 'view', tabindex: '-1' });
    app.replaceChildren(
      h('header', { class: 'top' },
        h('a', { class: 'brand', href: '#/' }, h('span', { class: 'logo', 'aria-hidden': 'true' }, 'MA'), h('span', {}, 'MyAduan', h('small', {}, 'Portal Admin'))),
        h('nav', { class: 'nav', 'aria-label': 'Menu utama' }, links.map(([href, label, key]) => h('a', { href, 'data-key': key }, label))),
        h('div', { class: 'who' },
          h('span', { class: 'who-name' }, me.name, h('small', {}, ROLE[me.role])),
          h('a', { href: cfg.profile }, 'Profil'),
          h('a', { href: cfg.logout }, 'Log keluar'))),
      view);
  }

  async function route() {
    const { parts, query } = parseHash();
    const key = parts[0] || '';
    for (const a of app.querySelectorAll('.nav a')) a.classList.toggle('active', a.dataset.key === key);
    const canCases = state.me.can.work || state.me.can.manage;
    try {
      if (key === 'kes' && parts[1] && canCases) await caseView(parseInt(parts[1], 10));
      else if (key === 'pengguna' && state.me.can.manage) await usersView();
      else if (canCases) { state.lastList = location.hash || '#/kes'; await listView(query); }
      else await summaryView();
    } catch (e) {
      view.replaceChildren(h('div', { class: 'card error-box' }, h('h2', {}, 'Tidak dapat dipaparkan'), h('p', {}, e.message),
        h('p', {}, h('a', { class: 'btn ghost', href: '#/kes' }, 'Kembali ke senarai'))));
    }
    window.scrollTo(0, 0);
  }

  async function loadCatalog() {
    state.catalog = await api('/admin/catalog');
    state.cats = new Map(state.catalog.categories.map((c) => [c.code, c]));
    state.duns = new Map(state.catalog.areas.map((d) => [d.slug, d]));
    state.areas = new Map(state.catalog.areas.flatMap((d) => d.localities.map((l) => [l.id, l.name])));
  }

  /* ---------- form controls ---------- */

  function field(label, control, { help, name, wide } = {}) {
    const id = control.id || (control.id = 'f-' + (name || control.name || Math.random().toString(36).slice(2)));
    return h('div', { class: 'field' + (wide ? ' wide' : ''), 'data-field': name || control.name || null },
      h('label', { for: id }, label), control,
      help ? h('small', { class: 'help' }, help) : null,
      h('small', { class: 'err', id: id + '-err' }));
  }
  function select(name, options, value, attrs = {}) {
    const el = h('select', Object.assign({ name }, attrs));
    for (const o of options) {
      if (o.group) {
        el.append(h('optgroup', { label: o.group }, o.options.map((x) => h('option', { value: x.value }, x.label))));
      } else {
        el.append(h('option', { value: o.value }, o.label));
      }
    }
    el.value = value == null ? '' : String(value);
    return el;
  }
  function categoryOptions(first) {
    const groups = new Map();
    for (const c of state.catalog.categories) {
      if (!groups.has(c.group_name)) groups.set(c.group_name, []);
      groups.get(c.group_name).push({ value: c.code, label: `${c.name} (${c.code})` });
    }
    return [...first, ...[...groups].map(([group, options]) => ({ group, options }))];
  }
  function dunOptions(first) { return [...first, ...state.catalog.areas.map((d) => ({ value: d.slug, label: d.name }))]; }

  function showErrors(form, err) {
    for (const el of form.querySelectorAll('.err')) el.textContent = '';
    for (const el of form.querySelectorAll('.invalid')) el.classList.remove('invalid');
    const box = form.querySelector('.form-error');
    if (box) box.textContent = '';
    if (!err) return;
    let placed = false;
    for (const [name, msg] of Object.entries(err.fields || {})) {
      const wrap = form.querySelector(`[data-field="${name}"]`);
      if (wrap) { wrap.querySelector('.err').textContent = msg; wrap.classList.add('invalid'); placed = true; }
    }
    if (box && (!placed || !err.fields)) box.textContent = err.message;
  }

  async function submitting(form, fn) {
    const btns = form.querySelectorAll('button');
    btns.forEach((b) => { b.disabled = true; });
    form.classList.add('busy');
    try { return await fn(); } finally { btns.forEach((b) => { b.disabled = false; }); form.classList.remove('busy'); }
  }

  /* ---------- case list ---------- */

  async function listView(query) {
    const me = state.me;
    const page = Math.max(1, parseInt(query.page || '1', 10));
    view.replaceChildren(h('p', { class: 'loading' }, 'Memuatkan senarai…'));
    const [stats, list] = await Promise.all([api('/admin/stats'), api('/admin/cases', { params: Object.assign({}, query, { page }) })]);

    const cards = [];
    const card = (label, n, q, tone) => cards.push(h('a', { class: 'stat ' + (tone || ''), href: hashFor('/kes', q) }, h('strong', {}, n), h('span', {}, label)));
    if (me.can.manage) {
      card('Belum dikelaskan', stats.unclassified, { category: 'none', flag: 'open' }, stats.unclassified ? 'warn' : '');
      card('Belum ditugaskan', stats.unassigned, { owner: 'unassigned', flag: 'open' }, stats.unassigned ? 'warn' : '');
      card('Menunggu pengesahan tutup', stats.by_status['MENUNGGU PENGESAHAN'], { status: 'MENUNGGU PENGESAHAN' }, stats.by_status['MENUNGGU PENGESAHAN'] ? 'warn' : '');
    } else {
      card('Kes saya (terbuka)', stats.mine_open, { owner: String(me.id), flag: 'open' });
    }
    card('Belum diterima pegawai', stats.not_accepted, { flag: 'not_accepted' }, stats.not_accepted ? 'warn' : '');
    card('Lewat tarikh susulan', stats.overdue, { flag: 'overdue' }, stats.overdue ? 'bad' : '');
    card('Jumlah aduan', stats.total, {});

    const ownerOpts = [{ value: '', label: 'Semua pegawai' }];
    if (me.can.manage) {
      ownerOpts.push({ value: 'unassigned', label: 'Belum ditugaskan' }, ...state.catalog.officers.map((o) => ({ value: String(o.id), label: o.name })));
    } else {
      ownerOpts.push({ value: String(me.id), label: 'Kes saya sahaja' });
    }
    const f = h('form', { class: 'filters card', role: 'search', onsubmit: (e) => {
      e.preventDefault();
      const q = Object.fromEntries(new FormData(f));
      location.hash = hashFor('/kes', q);
    } },
      field('Cari', h('input', { name: 'q', type: 'search', value: query.q || '', placeholder: 'No. rujukan, tajuk atau lokasi' }), { wide: true }),
      field('No. KP pengadu', h('input', { name: 'ic', inputmode: 'numeric', value: query.ic || '', placeholder: '880512-10-5432', autocomplete: 'off' })),
      field('Status', select('status', [{ value: '', label: 'Semua status' }, ...state.catalog.statuses.map((s) => ({ value: s, label: s }))], query.status)),
      field('Keadaan', select('flag', [{ value: '', label: 'Semua' }, ...Object.entries(FLAGS).map(([value, label]) => ({ value, label }))], query.flag)),
      field('Kategori', select('category', categoryOptions([{ value: '', label: 'Semua kategori' }, ...(me.can.manage ? [{ value: 'none', label: 'Belum dikelaskan' }] : [])]), query.category)),
      field('DUN', select('dun', dunOptions([{ value: '', label: 'Semua DUN' }, { value: 'none', label: 'Belum ditetapkan' }]), query.dun)),
      field('Pegawai', select('owner', ownerOpts, query.owner)),
      field('Dari', h('input', { name: 'from', type: 'date', value: query.from || '' })),
      field('Hingga', h('input', { name: 'to', type: 'date', value: query.to || '' })),
      h('div', { class: 'filter-actions' },
        h('button', { class: 'btn', type: 'submit' }, 'Tapis'),
        h('a', { class: 'btn ghost', href: '#/kes' }, 'Set semula')));

    const rows = list.items.map((c) => h('tr', { class: 'row', onclick: (e) => { if (!e.target.closest('a')) location.hash = '#/kes/' + c.id; } },
      h('td', { 'data-label': 'Rujukan' }, h('a', { class: 'ref', href: '#/kes/' + c.id }, c.reference), h('small', {}, formatDate(c.created_at))),
      h('td', { 'data-label': 'Aduan', class: 'main-col' }, h('span', { class: 'title' }, c.title), h('small', {}, c.location)),
      h('td', { 'data-label': 'Kategori' }, catName(c.category_code) || h('em', { class: 'muted' }, 'Belum dikelaskan'), h('small', {}, dunName(c.dun_slug) || '')),
      h('td', { 'data-label': 'Status' }, statusBadge(c.status), ' ', priorityBadge(c.priority),
        c.owner_id && !c.accepted && c.status !== 'DITUTUP' ? h('small', { class: 'warn-text' }, 'Belum diterima') : null),
      h('td', { 'data-label': 'Pegawai' }, c.owner_name || h('em', { class: 'muted' }, 'Belum ditugaskan'),
        c.followup_at ? h('small', { class: new Date(c.followup_at) < new Date() && c.status !== 'DITUTUP' ? 'bad-text' : '' }, 'Susulan ' + formatDate(c.followup_at, false)) : null)));

    const pages = Math.max(1, Math.ceil(list.total / list.per_page));
    const pager = h('nav', { class: 'pager', 'aria-label': 'Halaman' },
      h('span', {}, `${list.total} aduan · halaman ${list.page} / ${pages}`),
      page > 1 ? h('a', { class: 'btn ghost', href: hashFor('/kes', Object.assign({}, query, { page: page - 1 })) }, '‹ Sebelum') : null,
      page < pages ? h('a', { class: 'btn ghost', href: hashFor('/kes', Object.assign({}, query, { page: page + 1 })) }, 'Seterusnya ›') : null);

    view.replaceChildren(
      h('h1', {}, 'Senarai kes'),
      h('section', { class: 'stats', 'aria-label': 'Ringkasan' }, cards),
      f,
      list.items.length
        ? h('div', { class: 'card table-wrap' }, h('table', { class: 'cases' },
          h('thead', {}, h('tr', {}, ['Rujukan', 'Aduan', 'Kategori / DUN', 'Status', 'Pegawai'].map((t) => h('th', { scope: 'col' }, t)))),
          h('tbody', {}, rows)))
        : h('div', { class: 'card empty' }, 'Tiada aduan yang sepadan dengan tapisan ini.'),
      pager);
  }

  /* ---------- summary (viewer) ---------- */

  async function summaryView() {
    const stats = await api('/admin/stats');
    view.replaceChildren(
      h('h1', {}, 'Ringkasan aduan'),
      h('section', { class: 'stats' },
        h('div', { class: 'stat' }, h('strong', {}, stats.total), h('span', {}, 'Jumlah aduan')),
        state.catalog.statuses.map((s) => h('div', { class: 'stat' }, h('strong', {}, stats.by_status[s]), h('span', {}, s))),
        h('div', { class: 'stat ' + (stats.overdue ? 'bad' : '') }, h('strong', {}, stats.overdue), h('span', {}, 'Lewat tarikh susulan'))),
      h('p', { class: 'card muted' }, 'Dashboard penuh (ikut DUN, kategori, bulan dan peta) akan datang dalam fasa seterusnya.'));
  }

  /* ---------- case detail ---------- */

  async function caseView(id) {
    view.replaceChildren(h('p', { class: 'loading' }, 'Memuatkan aduan…'));
    renderCase(await api('/admin/cases/' + id));
  }

  function renderCase(c) {
    const dl = (pairs) => h('dl', { class: 'facts' }, pairs.filter(([, v]) => v != null && v !== '').map(([k, v]) => [h('dt', {}, k), h('dd', {}, v)]));
    const phone = c.complainant.phone || '';
    const map = c.lat != null && c.lng != null ? h('a', { href: `https://www.google.com/maps?q=${c.lat},${c.lng}`, target: '_blank', rel: 'noopener' }, 'Buka peta') : null;

    const photos = c.attachments.filter((a) => a.mime.startsWith('image/'));
    const main = h('div', { class: 'col-main' },
      h('section', { class: 'card' },
        h('h2', {}, 'Butiran aduan'),
        h('p', { class: 'details' }, c.details),
        dl([['Lokasi', h('span', {}, c.location, map ? ' · ' : '', map)], ['Diterima', formatDate(c.created_at)]]),
        photos.length ? h('div', { class: 'photos' }, photos.map((a) => h('a', { href: fileUrl(a.id), target: '_blank', rel: 'noopener', title: a.name },
          h('img', { src: fileUrl(a.id), alt: 'Gambar aduan: ' + a.name, loading: 'lazy' }),
          h('small', {}, a.kind === 'public' ? 'Dari pengadu' : 'Dari pegawai')))) : null),
      h('section', { class: 'card' },
        h('h2', {}, 'Pengadu'),
        h('p', { class: 'muted small' }, 'Maklumat peribadi. Setiap kali kes dibuka direkodkan.'),
        dl([
          ['Nama', c.complainant.name],
          ['No. KP', formatIc(c.complainant.ic)],
          ['Telefon', phone ? h('span', {}, h('a', { href: 'tel:+' + phone }, '+' + phone), ' · ',
            h('a', { href: 'https://wa.me/' + phone, target: '_blank', rel: 'noopener' }, 'WhatsApp')) : null],
        ])),
      h('section', { class: 'card' },
        h('h2', {}, 'Maklumat kes'),
        dl([
          ['Kategori', catName(c.category_code) || h('em', { class: 'muted' }, 'Belum dikelaskan')],
          ['DUN', dunName(c.dun_slug) || h('em', { class: 'muted' }, 'Belum ditetapkan')],
          ['Taman/kampung', c.area_id ? state.areas.get(c.area_id) || '#' + c.area_id : null],
          ['Pegawai', c.owner_name ? `${c.owner_name}${c.accepted_at ? ' (diterima ' + formatDate(c.accepted_at) + ')' : ' (belum diterima)'}` : h('em', { class: 'muted' }, 'Belum ditugaskan')],
          ['Tindakan seterusnya', c.next_action],
          ['Tarikh susulan', c.followup_at ? formatDate(c.followup_at) : null],
          ['Tindakan terakhir', c.last_action_at ? formatDate(c.last_action_at) : null],
          ['Sebab penutupan', c.closure_reason],
          ['Penyelesaian', c.resolution],
          ['Draf mesej awam', c.status === 'MENUNGGU PENGESAHAN' ? c.public_draft : null],
          ['Mesej awam', c.public_message],
          ['Dibuka semula', c.reopen_count ? c.reopen_count + ' kali' : null],
          ['Ditutup', c.closed_at ? formatDate(c.closed_at) : null],
        ])),
      h('section', { class: 'card' },
        h('h2', {}, 'Sejarah'),
        h('ol', { class: 'timeline' }, c.events.map(eventItem))));

    const side = h('aside', { class: 'col-side' }, actionPanel(c));

    view.replaceChildren(
      h('a', { class: 'back', href: state.lastList }, '‹ Senarai kes'),
      h('div', { class: 'case-head' },
        h('div', {}, h('code', { class: 'ref' }, c.reference), h('h1', {}, c.title)),
        h('div', { class: 'badges' }, statusBadge(c.status), priorityBadge(c.priority))),
      h('div', { class: 'case-grid' }, main, side));
  }

  function changeValue(field, v) {
    if (v == null || v === '') return '(kosong)';
    switch (field) {
      case 'category_code': return catName(v);
      case 'dun_slug': return dunName(v);
      case 'area_id': return state.areas.get(Number(v)) || '#' + v;
      case 'owner_id': return Number(v) ? officerName(Number(v)) : '(tiada)';
      case 'followup_at': return formatDate(String(v).replace(' ', 'T') + 'Z');
      default: return String(v);
    }
  }

  function eventItem(e) {
    const changes = Object.entries(e.changes || {}).filter(([k]) => FIELD[k]);
    return h('li', {},
      h('div', { class: 'ev-head' }, h('strong', {}, EVENT[e.event] || e.event), h('span', { class: 'muted' }, ` · ${e.actor_name} · ${formatDate(e.created_at)}`)),
      e.status_from && e.status_to && e.status_from !== e.status_to ? h('div', { class: 'small' }, statusBadge(e.status_from), ' → ', statusBadge(e.status_to)) : null,
      changes.length ? h('ul', { class: 'changes' }, changes.map(([k, [from, to]]) => h('li', {},
        k === 'gambar' ? `${to} gambar dilampirkan` : `${FIELD[k]}: ${changeValue(k, from)} → ${changeValue(k, to)}`))) : null,
      e.note ? h('p', { class: 'note' }, e.note) : null);
  }

  /* ---------- case actions ---------- */

  function actionPanel(c) {
    const ops = c.allowed_operations;
    const panel = h('section', { class: 'card actions' }, h('h2', {}, 'Tindakan'));
    const area = h('div', { class: 'op-area' });
    const buttons = [];
    const add = (key, label, build, primary) => buttons.push(h('button', { type: 'button', class: 'btn ' + (primary ? '' : 'ghost'), 'data-op': key,
      onclick: (ev) => {
        for (const b of panel.querySelectorAll('[data-op]')) b.classList.toggle('active', b === ev.currentTarget);
        area.replaceChildren(build(c));
        area.querySelector('input, select, textarea, button[type=submit]')?.focus();
      } }, label));

    if (ops.includes('classify') || ops.includes('assign')) add('screen', ops.includes('classify') ? 'Kelaskan / tugaskan' : 'Tugaskan semula', screenForm, !c.owner_id);
    if (ops.includes('accept')) add('accept', 'Terima tugasan', acceptForm, true);
    if (ops.includes('action')) add('action', 'Rekod tindakan', actionForm, true);
    if (ops.includes('request_close')) add('request_close', 'Mohon penutupan', closeRequestForm);
    if (ops.includes('approve_close')) add('approve_close', 'Sahkan & tutup', approveForm, true);
    if (ops.includes('return')) add('return', 'Kembalikan', (x) => noteForm(x, 'return', 'Kembalikan untuk tindakan', 'Sebab dikembalikan dan apa yang perlu dibuat'));
    if (ops.includes('reopen')) add('reopen', 'Buka semula', (x) => noteForm(x, 'reopen', 'Buka semula', 'Sebab kes dibuka semula'));
    if (ops.includes('publish')) add('publish', 'Mesej awam', publishForm);
    if (ops.includes('comment')) add('comment', 'Catatan dalaman', (x) => noteForm(x, 'comment', 'Simpan catatan', 'Catatan untuk pasukan (tidak dilihat pengadu)'));

    if (!buttons.length) {
      panel.append(h('p', { class: 'muted' }, c.status === 'DITUTUP' ? 'Kes ini telah ditutup.' : 'Tiada tindakan untuk anda pada kes ini. Hanya pegawai yang ditugaskan boleh mengemas kini.'));
      return panel;
    }
    panel.append(h('div', { class: 'op-buttons' }, buttons), area);
    if (c.status === 'MENUNGGU PENGESAHAN' && ops.includes('approve_close')) buttons.find((b) => b.dataset.op === 'approve_close').click();
    else if (buttons.length === 1 || (buttons[0].dataset.op === 'accept')) buttons[0].click();
    return panel;
  }

  /** Builds an operation form; `collect` returns the fields to send (or the op name with fields). */
  function opForm(c, children, submitLabel, collect) {
    const requestId = newKey(); // reused on retry, so a double submit is applied once
    const form = h('form', { class: 'op-form', novalidate: true, onsubmit: async (e) => {
      e.preventDefault();
      showErrors(form);
      const { op, fields } = collect(form);
      await submitting(form, async () => {
        try {
          const updated = await api(`/admin/cases/${c.id}/operations`, { method: 'POST', body: Object.assign({ op, version: c.version, request_id: requestId }, fields) });
          toast('Disimpan.');
          renderCase(updated);
        } catch (err) {
          showErrors(form, err);
          if (err.status === 409) {
            form.querySelector('.form-error').append(' ', h('button', { type: 'button', class: 'link', onclick: () => caseView(c.id) }, 'Muat semula kes'));
          } else if (err.status === 404) {
            form.querySelector('.form-error').textContent = 'Anda tidak lagi mempunyai akses kepada kes ini.';
          }
        }
      });
    } }, children, h('p', { class: 'form-error', role: 'alert' }), h('div', { class: 'form-actions' }, h('button', { class: 'btn', type: 'submit' }, submitLabel)));
    return form;
  }

  const textarea = (name, value, attrs = {}) => h('textarea', Object.assign({ name, rows: 4, value: value || '' }, attrs));
  const val = (form, name) => form.elements[name]?.value ?? '';

  function noteForm(c, op, label, help) {
    return opForm(c, [field('Catatan', textarea('note', '', { required: true, minlength: 10 }), { help: help + ' (sekurang-kurangnya 10 aksara).' })],
      label, (f) => ({ op, fields: { note: val(f, 'note') } }));
  }

  function acceptForm(c) {
    return opForm(c, [h('p', {}, 'Terima tugasan ini untuk mula mengambil tindakan. Status akan bertukar kepada DALAM TINDAKAN.')],
      'Terima tugasan', () => ({ op: 'accept', fields: {} }));
  }

  function actionForm(c) {
    return opForm(c, [
      field('Tindakan yang diambil', textarea('note', '', { required: true }), { help: 'Contoh: Lawatan tapak, aduan dipanjangkan kepada MPS (sekurang-kurangnya 10 aksara).' }),
      field('Tindakan seterusnya', h('input', { name: 'next_action', value: c.next_action || '', maxlength: 500 })),
      field('Tarikh susulan', h('input', { name: 'followup_at', type: 'datetime-local', value: toLocalInput(c.followup_at) }), { help: 'Kosongkan jika tiada susulan.' }),
    ], 'Simpan tindakan', (f) => ({ op: 'action', fields: { note: val(f, 'note'), next_action: val(f, 'next_action'), followup_at: val(f, 'followup_at') } }));
  }

  function closeRequestForm(c) {
    return opForm(c, [
      field('Sebab penutupan', select('closure_reason', [{ value: '', label: 'Pilih…' }, ...state.catalog.closure_reasons.map((r) => ({ value: r, label: r }))], c.closure_reason)),
      field('Penyelesaian (dalaman)', textarea('resolution', c.resolution), { help: 'Apa yang telah dibuat untuk selesaikan aduan.' }),
      field('Draf mesej kepada pengadu', textarea('public_draft', c.public_draft), { help: 'Dipaparkan kepada pengadu di halaman semak status selepas Super Admin sahkan.' }),
      field('Catatan (pilihan)', textarea('note', '', { rows: 2 })),
    ], 'Hantar untuk pengesahan', (f) => ({ op: 'request_close', fields: {
      closure_reason: val(f, 'closure_reason'), resolution: val(f, 'resolution'), public_draft: val(f, 'public_draft'), note: val(f, 'note'),
    } }));
  }

  function approveForm(c) {
    return opForm(c, [
      dlBlock('Penyelesaian', c.resolution), dlBlock('Sebab', c.closure_reason),
      field('Mesej kepada pengadu', textarea('public_message', c.public_draft), { help: 'Semak dan betulkan jika perlu. Mesej ini dipaparkan kepada pengadu.' }),
      field('Catatan (pilihan)', textarea('note', '', { rows: 2 })),
    ], 'Sahkan & tutup kes', (f) => ({ op: 'approve_close', fields: { public_message: val(f, 'public_message'), note: val(f, 'note') } }));
  }

  function publishForm(c) {
    return opForm(c, [
      field('Mesej kepada pengadu', textarea('public_message', c.public_message), { help: 'Dipaparkan di halaman semak status.' }),
    ], 'Kemas kini mesej awam', (f) => ({ op: 'publish', fields: { public_message: val(f, 'public_message') } }));
  }

  function dlBlock(label, text) { return text ? h('div', { class: 'quote' }, h('small', {}, label), h('p', {}, text)) : null; }

  /** Officers who may hold a case in this category (the server checks again). */
  function eligibleOfficers(category) {
    return state.catalog.officers.filter((o) => o.manager || (category && (o.policy.mode === 'all' || (o.policy.mode === 'selected' && o.policy.codes.includes(category)))));
  }

  function screenForm(c) {
    const ops = c.allowed_operations;
    const cat = select('category_code', categoryOptions([{ value: '', label: 'Belum dikelaskan' }]), c.category_code);
    const dun = select('dun_slug', dunOptions([{ value: '', label: 'Belum ditetapkan' }]), c.dun_slug);
    const area = h('select', { name: 'area_id' });
    const owner = h('select', { name: 'owner_id' });
    const submit = () => form.querySelector('button[type=submit]');

    const fillAreas = (keep) => {
      const list = state.duns.get(dun.value)?.localities || [];
      area.replaceChildren(h('option', { value: '' }, list.length ? 'Tidak pasti / pilih' : 'Senarai taman belum dimasukkan'),
        ...list.map((l) => h('option', { value: String(l.id) }, l.name)));
      area.disabled = !list.length;
      area.value = keep && list.some((l) => String(l.id) === keep) ? keep : '';
    };
    const fillOwners = () => {
      const keep = owner.value || (c.owner_id ? String(c.owner_id) : '');
      const list = eligibleOfficers(cat.value);
      owner.replaceChildren(h('option', { value: '' }, c.owner_id ? '— Kekal —' : '— Belum ditugaskan —'),
        ...list.map((o) => h('option', { value: String(o.id) }, o.name + (o.manager ? ' (Super Admin)' : ''))));
      owner.value = list.some((o) => String(o.id) === keep) ? keep : '';
      updateLabel();
    };
    const updateLabel = () => {
      const b = submit();
      if (b) b.textContent = owner.value && Number(owner.value) !== c.owner_id ? 'Simpan & tugaskan' : 'Simpan klasifikasi';
    };
    cat.addEventListener('change', fillOwners);
    dun.addEventListener('change', () => fillAreas(''));
    owner.addEventListener('change', updateLabel);
    fillAreas(c.area_id ? String(c.area_id) : '');

    const form = opForm(c, [
      field('Tajuk', h('input', { name: 'title', value: c.title, maxlength: 150 })),
      field('Kategori', cat, { name: 'category_code' }),
      h('div', { class: 'two' }, field('DUN', dun, { name: 'dun_slug' }), field('Taman/kampung', area, { name: 'area_id' })),
      field('Keutamaan', select('priority', state.catalog.priorities.map((p) => ({ value: p, label: p })), c.priority)),
      field('Pegawai', owner, { name: 'owner_id', help: 'Hanya pegawai yang dibenarkan untuk kategori ini disenaraikan.' }),
      field('Catatan (pilihan)', textarea('note', '', { rows: 2 })),
    ], 'Simpan klasifikasi', (f) => {
      const fields = {
        title: val(f, 'title'), category_code: cat.value || null, dun_slug: dun.value || null,
        area_id: dun.value && area.value ? Number(area.value) : null, priority: val(f, 'priority'), note: val(f, 'note'),
      };
      const reassign = owner.value && Number(owner.value) !== c.owner_id;
      if (reassign || !ops.includes('classify')) {
        fields.owner_id = owner.value ? Number(owner.value) : (c.owner_id || null);
        return { op: 'assign', fields };
      }
      return { op: 'classify', fields };
    });
    fillOwners();
    if (!ops.includes('classify')) {
      for (const n of ['title', 'category_code', 'dun_slug', 'area_id', 'priority']) form.elements[n].disabled = c.status === 'DITUTUP';
    }
    return form;
  }

  /* ---------- users ---------- */

  async function usersView(editId) {
    view.replaceChildren(h('p', { class: 'loading' }, 'Memuatkan pengguna…'));
    const users = await api('/admin/users');
    const panel = h('div', { class: 'user-panel' });
    const open = (u) => {
      panel.replaceChildren(userForm(u, panel, users));
      panel.scrollIntoView({ block: 'start' });
      panel.querySelector('input')?.focus();
    };
    const policyText = (u) => {
      if (u.role === 'superadmin') return 'Semua (Super Admin)';
      if (u.role === 'viewer') return 'Ringkasan sahaja';
      if (u.policy.mode === 'all') return 'Semua kategori';
      if (u.policy.mode === 'selected') return u.policy.codes.map(catName).join(', ');
      return '—';
    };
    const rows = users.map((u) => h('tr', { class: u.active ? '' : 'inactive' },
      h('td', { 'data-label': 'Nama', class: 'main-col' }, h('span', { class: 'title' }, u.name), h('small', {}, u.email)),
      h('td', { 'data-label': 'Peranan' }, ROLE[u.role], u.site_admin ? h('small', {}, 'Pentadbir laman') : null),
      h('td', { 'data-label': 'Kategori', class: 'cats' }, policyText(u)),
      h('td', { 'data-label': 'Kes terbuka' }, String(u.open_cases)),
      h('td', { 'data-label': 'Status' }, h('span', { class: 'badge ' + (u.active ? 'st-ok' : 'st-off') }, u.active ? 'Aktif' : 'Tidak aktif')),
      h('td', { 'data-label': '' }, u.site_admin ? h('small', { class: 'muted' }, 'Urus dalam WordPress')
        : h('button', { class: 'btn ghost small', type: 'button', onclick: () => open(u) }, 'Ubah'))));

    view.replaceChildren(
      h('div', { class: 'page-head' }, h('h1', {}, 'Pengguna'), h('button', { class: 'btn', type: 'button', onclick: () => open(null) }, '+ Tambah pengguna')),
      panel,
      h('div', { class: 'card table-wrap' }, h('table', { class: 'users' },
        h('thead', {}, h('tr', {}, ['Nama', 'Peranan', 'Kategori dibenarkan', 'Kes terbuka', 'Status', ''].map((t) => h('th', { scope: 'col' }, t)))),
        h('tbody', {}, rows))),
      h('p', { class: 'muted small' }, 'Pengguna baru menerima e-mel untuk menetapkan kata laluan sendiri. Akaun yang dinyahaktifkan tidak boleh log masuk, tetapi sejarah kesnya kekal.'));
    if (editId) { const u = users.find((x) => x.id === editId); if (u) open(u); }
  }

  function userForm(u, panel) {
    const isNew = !u;
    const role = u?.role && u.role !== 'none' ? u.role : 'officer';
    const policy = u?.policy?.mode && u.policy.mode !== 'none' ? u.policy : { mode: 'selected', codes: [] };

    const roleBox = h('fieldset', { class: 'field wide', 'data-field': 'role' }, h('legend', {}, 'Peranan'),
      Object.keys(ROLE_HELP).map((r) => h('label', { class: 'choice' },
        h('input', { type: 'radio', name: 'role', value: r, checked: r === role }),
        h('span', {}, h('strong', {}, ROLE[r]), h('small', {}, ROLE_HELP[r])))),
      h('small', { class: 'err' }));

    const groups = new Map();
    for (const c of state.catalog.categories) {
      if (!groups.has(c.group_name)) groups.set(c.group_name, []);
      groups.get(c.group_name).push(c);
    }
    const catBox = h('div', { class: 'cat-groups' }, [...groups].map(([g, list]) => {
      const boxes = list.map((c) => h('label', { class: 'check' }, h('input', { type: 'checkbox', name: 'codes', value: c.code, checked: policy.codes.includes(c.code) }), `${c.name} (${c.code})`));
      const all = h('input', { type: 'checkbox', 'aria-label': 'Pilih semua dalam ' + g, onchange: (e) => boxes.forEach((b) => { b.firstChild.checked = e.target.checked; }) });
      return h('fieldset', { class: 'cat-group' }, h('legend', {}, h('label', { class: 'check' }, all, g)), boxes);
    }));
    const policyBox = h('fieldset', { class: 'field wide', 'data-field': 'policy' }, h('legend', {}, 'Kategori yang dibenarkan'),
      h('div', { class: 'choice-row' },
        h('label', { class: 'choice inline' }, h('input', { type: 'radio', name: 'mode', value: 'all', checked: policy.mode === 'all' }), h('span', {}, 'Semua kategori')),
        h('label', { class: 'choice inline' }, h('input', { type: 'radio', name: 'mode', value: 'selected', checked: policy.mode === 'selected' }), h('span', {}, 'Kategori terpilih sahaja'))),
      catBox, h('small', { class: 'err' }));

    const form = h('form', { class: 'card user-form', novalidate: true, onsubmit: async (e) => {
      e.preventDefault();
      showErrors(form);
      const r = form.elements.role.value;
      const body = { name: form.elements.name.value, email: form.elements.email.value, role: r };
      if (r === 'officer') {
        body.policy = { mode: form.elements.mode.value, codes: [...form.querySelectorAll('input[name=codes]:checked')].map((x) => x.value) };
      }
      if (!isNew) body.active = form.elements.active.checked;
      await submitting(form, async () => {
        try {
          const saved = await api(isNew ? '/admin/users' : '/admin/users/' + u.id, { method: 'POST', body });
          toast(isNew ? `Akaun dicipta. E-mel untuk menetapkan kata laluan dihantar ke ${saved.email}.` : 'Perubahan disimpan.');
          await loadCatalog();
          await usersView();
        } catch (err) { showErrors(form, err); }
      });
    } },
      h('h2', {}, isNew ? 'Tambah pengguna' : 'Ubah ' + u.name),
      h('div', { class: 'two' },
        field('Nama penuh', h('input', { name: 'name', value: u?.name || '', autocomplete: 'off', required: true })),
        field('E-mel', h('input', { name: 'email', type: 'email', value: u?.email || '', autocomplete: 'off', required: true }), { help: isNew ? 'Pautan tetapan kata laluan dihantar ke e-mel ini.' : null })),
      roleBox, policyBox,
      isNew ? null : h('label', { class: 'check field wide' }, h('input', { type: 'checkbox', name: 'active', checked: u.active }), 'Akaun aktif (boleh log masuk)'),
      !isNew && u.open_cases ? h('p', { class: 'muted small' }, `Pengguna ini memegang ${u.open_cases} kes terbuka. Tugaskan semula kes tersebut sebelum menyahaktifkan atau membuang kategorinya.`) : null,
      h('p', { class: 'form-error', role: 'alert' }),
      h('div', { class: 'form-actions' },
        h('button', { class: 'btn', type: 'submit' }, isNew ? 'Cipta akaun' : 'Simpan'),
        h('button', { class: 'btn ghost', type: 'button', onclick: () => panel.replaceChildren() }, 'Batal')));

    const sync = () => {
      const officer = form.elements.role.value === 'officer';
      policyBox.hidden = !officer;
      catBox.hidden = form.elements.mode.value !== 'selected';
    };
    form.addEventListener('change', sync);
    sync();
    return form;
  }

  /* ---------- boot ---------- */

  async function boot() {
    try {
      state.me = await api('/admin/me');
      await loadCatalog();
    } catch (e) {
      app.replaceChildren(h('main', { class: 'denied' }, h('h1', {}, 'Portal tidak dapat dimuatkan'), h('p', {}, e.message),
        h('p', {}, h('a', { class: 'btn', href: location.pathname }, 'Cuba lagi'))));
      return;
    }
    shell();
    window.addEventListener('hashchange', route);
    await route();
  }

  boot();
})();
