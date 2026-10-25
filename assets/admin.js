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
    CIPTA: 'Aduan diterima', IMPORT: 'Diimport dari Google Sheet lama', CLASSIFY: 'Dikelaskan', ASSIGN: 'Ditugaskan', ACCEPT: 'Tugasan diterima', ACTION: 'Tindakan direkod',
    COMMENT: 'Catatan dalaman', REQUEST_CLOSE: 'Mohon penutupan', APPROVE_CLOSE: 'Disahkan & ditutup', RETURN: 'Dikembalikan untuk tindakan',
    REOPEN: 'Dibuka semula', PUBLISH: 'Mesej awam dikemas kini', REFER: 'Dipanjangkan ke agensi', SELF: 'Tindakan sendiri',
    NEED_INFO: 'Perlu maklumat lanjut', AGENCY_UPDATE: 'Status agensi dikemas kini', AGENCY_REPLY: 'Balasan agensi (e-mel)',
  };
  const FIELD = {
    title: 'Tajuk', category_code: 'Kategori', dun_slug: 'DUN', area_id: 'Taman/kampung', priority: 'Keutamaan', owner_id: 'Pegawai',
    next_action: 'Tindakan seterusnya', followup_at: 'Tarikh susulan', closure_reason: 'Sebab penutupan', resolution: 'Penyelesaian',
    public_draft: 'Draf mesej awam', public_message: 'Mesej awam', gambar: 'Gambar', track: 'Keputusan', agencies: 'Agensi',
    agency: 'Agensi', agency_status: 'Status agensi', agency_reference: 'No. rujukan agensi',
  };
  const FLAGS = {
    open: 'Masih terbuka', not_accepted: 'Belum diterima pegawai', overdue: 'Lewat tarikh susulan',
    agency: 'Dipanjangkan ke agensi', need_info: 'Perlu maklumat lanjut',
  };

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
  function trackBadge(track, status) {
    return track && status !== 'DITUTUP' ? h('span', { class: 'badge tr-' + slug(track) }, state.catalog.tracks[track] || track) : null;
  }
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
      const url = apiUrl(path, params);
      // A fresh address each time, so no server or CDN cache can hand back an old answer.
      if (method === 'GET') url.searchParams.set('_', Date.now().toString(36));
      res = await fetch(url, {
        method,
        cache: 'no-store',
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
  let bell;
  function shell() {
    const me = state.me;
    const links = [];
    if (me.can.work || me.can.manage) links.push(['#/kes', 'Senarai kes', 'kes']);
    else links.push(['#/ringkasan', 'Ringkasan', 'ringkasan']);
    if (me.can.manage) links.push(['#/agensi', 'Agensi', 'agensi'], ['#/emel', 'E-mel', 'emel'], ['#/pengguna', 'Pengguna', 'pengguna']);
    view = h('main', { id: 'view', tabindex: '-1' });
    const NS = 'http://www.w3.org/2000/svg';
    const icon = document.createElementNS(NS, 'svg');
    const path = document.createElementNS(NS, 'path');
    for (const [k, v] of Object.entries({ viewBox: '0 0 24 24', width: '22', height: '22', 'aria-hidden': 'true' })) icon.setAttribute(k, v);
    path.setAttribute('fill', 'currentColor');
    path.setAttribute('d', 'M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2Z');
    icon.append(path);
    bell = h('a', { class: 'bell', href: '#/notifikasi', 'data-key': 'notifikasi', 'aria-label': 'Notifikasi' }, icon, h('span', { class: 'count', hidden: true }));
    app.replaceChildren(
      h('header', { class: 'top' },
        h('a', { class: 'brand', href: '#/' }, cfg.logo ? h('img', { class: 'logo-img', src: cfg.logo, alt: '', width: '38', height: '38' }) : h('span', { class: 'logo', 'aria-hidden': 'true' }, 'MA'), h('span', {}, 'MyAduan', h('small', {}, 'Portal Admin'))),
        h('nav', { class: 'nav', 'aria-label': 'Menu utama' }, links.map(([href, label, key]) => h('a', { href, 'data-key': key }, label))),
        h('div', { class: 'who' },
          bell,
          h('span', { class: 'who-name' }, me.name, h('small', {}, ROLE[me.role])),
          h('a', { href: '#/profil', 'data-key': 'profil' }, 'Profil'),
          h('a', { href: cfg.logout, onclick: logout }, 'Log keluar'))),
      view);
  }

  async function route() {
    const { parts, query } = parseHash();
    const key = parts[0] || '';
    for (const a of app.querySelectorAll('.nav a, a.bell, .who a[data-key]')) a.classList.toggle('active', a.dataset.key === key);
    const canCases = state.me.can.work || state.me.can.manage;
    try {
      if (key === 'kes' && parts[1] && canCases) await caseView(parseInt(parts[1], 10));
      else if (key === 'pengguna' && state.me.can.manage) await usersView();
      else if (key === 'agensi' && state.me.can.manage) await agenciesView();
      else if (key === 'emel' && state.me.can.manage) await mailView(query);
      else if (key === 'notifikasi') await notificationsView();
      else if (key === 'profil') profileView();
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
  // A password box with a show/hide button, so a Super Admin can read back what they typed.
  function passwordInput(name, attrs = {}) {
    const input = h('input', Object.assign({ name, type: 'password', autocomplete: 'new-password', minlength: '8', maxlength: '100', spellcheck: 'false', autocapitalize: 'off' }, attrs));
    const btn = h('button', { class: 'btn ghost small pw-toggle', type: 'button', 'aria-pressed': 'false', onclick: () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Sembunyi' : 'Tunjuk';
      btn.setAttribute('aria-pressed', String(show));
    } }, 'Tunjuk');
    input.id = 'f-' + name + '-' + Math.random().toString(36).slice(2, 7);
    return { input, wrap: h('div', { class: 'pw-wrap' }, input, btn) };
  }
  const PW_HELP = 'Sekurang-kurangnya 8 aksara, dengan huruf dan nombor.';

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
      h('td', { 'data-label': 'Status' }, statusBadge(c.status), ' ', priorityBadge(c.priority), ' ', trackBadge(c.track, c.status),
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
          ['E-mel', c.complainant.email ? h('a', { href: 'mailto:' + c.complainant.email }, c.complainant.email) : null],
        ])),
      h('section', { class: 'card' },
        h('h2', {}, 'Maklumat kes'),
        dl([
          ['Kategori', catName(c.category_code) || h('em', { class: 'muted' }, 'Belum dikelaskan')],
          ['DUN', dunName(c.dun_slug) || h('em', { class: 'muted' }, 'Belum ditetapkan')],
          ['Taman/kampung', c.area_id ? state.areas.get(c.area_id) || '#' + c.area_id : null],
          ['Pegawai', c.owner_name ? `${c.owner_name}${c.accepted_at ? ' (diterima ' + formatDate(c.accepted_at) + ')' : ' (belum diterima)'}` : h('em', { class: 'muted' }, 'Belum ditugaskan')],
          ['Keputusan', c.track ? state.catalog.tracks[c.track] : null],
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
      c.referrals.length ? h('section', { class: 'card' },
        h('h2', {}, 'Dipanjangkan ke agensi'),
        h('ul', { class: 'referrals' }, c.referrals.map((r) => h('li', {},
          h('div', {}, h('strong', {}, r.agency_name), ' ', h('span', { class: 'badge ag-' + slug(r.status) }, r.status), ' ', mailBadge(r.mail)),
          r.mail?.error ? h('small', { class: 'bad-text' }, (r.mail.state === 'bounced' ? 'Sebab bounce: ' : 'Ralat: ') + r.mail.error) : null,
          h('small', { class: 'muted' }, `Dihantar ${formatDate(r.created_at)}` + (r.agency_email ? ' ke ' + r.agency_email : '')
            + (r.reference_no ? ` · Rujukan agensi: ${r.reference_no}` : '') + (r.updated_at !== r.created_at ? ` · Dikemas kini ${formatDate(r.updated_at)}` : '')))))) : null,
      h('section', { class: 'card' },
        h('h2', {}, 'Sejarah'),
        h('ol', { class: 'timeline' }, c.events.map(eventItem))));

    const side = h('aside', { class: 'col-side' }, actionPanel(c));

    view.replaceChildren(
      h('a', { class: 'back', href: state.lastList }, '‹ Senarai kes'),
      h('div', { class: 'case-head' },
        h('div', {}, h('code', { class: 'ref' }, c.reference), h('h1', {}, c.title)),
        h('div', { class: 'badges' }, statusBadge(c.status), priorityBadge(c.priority), trackBadge(c.track, c.status))),
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
      case 'track': return state.catalog.tracks[v] || v;
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
      e.event === 'AGENCY_REPLY' && e.changes?.subject ? h('small', { class: 'muted' }, `${e.changes.email || ''} · ${e.changes.subject}`) : null,
      e.note ? h('p', { class: 'note' }, e.note) : null);
  }

  const MAIL_HELP = {
    pending: 'Masih dalam sistem MyAduan, belum keluar.',
    sending: 'Sedang dihantar ke pelayan e-mel.',
    sent: 'Pelayan e-mel kita telah menghantarnya keluar. Jika penerima menolak, status bertukar kepada Bounce.',
    failed: 'Tidak dapat dihantar selepas beberapa cubaan. Lihat ralat.',
    bounced: 'Pelayan penerima menolak e-mel ini. Lihat sebab.',
  };
  function mailBadge(m) {
    if (!m) return null;
    return h('span', { class: 'badge mail-' + m.state, title: MAIL_HELP[m.state] || '' }, 'E-mel: ' + m.label);
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
    if (ops.includes('refer')) add('refer', 'Panjangkan ke agensi', referForm, true);
    if (ops.includes('self')) add('self', 'Tindakan sendiri', selfForm);
    if (ops.includes('need_info')) add('need_info', 'Perlu maklumat lanjut', needInfoForm);
    if (ops.includes('agency_update')) add('agency_update', 'Status agensi', agencyUpdateForm);
    if (ops.includes('action')) add('action', 'Rekod tindakan', actionForm);
    if (ops.includes('request_close')) add('request_close', 'Tutup kes', closeRequestForm);
    if (ops.includes('approve_close')) add('approve_close', 'Sahkan & tutup', approveForm, true);
    if (ops.includes('return')) add('return', 'Kembalikan', (x) => noteForm(x, 'return', 'Kembalikan untuk tindakan', 'Sebab dikembalikan dan apa yang perlu dibuat'));
    if (ops.includes('reopen')) add('reopen', 'Buka semula', (x) => noteForm(x, 'reopen', 'Buka semula', 'Sebab kes dibuka semula'));
    if (ops.includes('publish')) add('publish', 'Mesej awam', publishForm);
    if (ops.includes('comment')) add('comment', 'Catatan dalaman', (x) => noteForm(x, 'comment', 'Simpan catatan', 'Catatan untuk pasukan (tidak dilihat pengadu)'));

    if (!c.owner_id && c.status !== 'DITUTUP') {
      panel.append(h('p', { class: 'help-box' }, state.me.can.manage
        ? 'Kes ini belum ditugaskan. Tugaskan kepada pegawai atau diri sendiri untuk mula memproses.'
        : 'Kes ini belum ditugaskan. Anda boleh membaca kes, tetapi tindakan hanya boleh dibuat selepas Super Admin menugaskannya.'));
    }
    if (!buttons.length) {
      if (c.owner_id || c.status === 'DITUTUP') {
        panel.append(h('p', { class: 'muted' }, c.status === 'DITUTUP' ? 'Kes ini telah ditutup.' : 'Tiada tindakan untuk anda pada kes ini. Hanya pegawai yang ditugaskan boleh mengemas kini.'));
      }
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

  /** Follow-up fields shared by the decision forms. */
  function followupFields(c) {
    return [
      field('Tindakan seterusnya (pilihan)', h('input', { name: 'next_action', value: c.next_action || '', maxlength: 500 })),
      field('Tarikh susulan (pilihan)', h('input', { name: 'followup_at', type: 'datetime-local', value: toLocalInput(c.followup_at) }), { help: 'Anda akan nampak kes ini dalam "Lewat tarikh susulan" selepas tarikh ini.' }),
    ];
  }
  const followupValues = (f) => ({ next_action: val(f, 'next_action'), followup_at: val(f, 'followup_at') });

  function referForm(c) {
    const already = new Set(c.referrals.map((r) => r.agency_id));
    const search = h('input', { type: 'search', placeholder: 'Cari agensi (contoh: MPS, JKR, polis)', 'aria-label': 'Cari agensi' });
    const count = h('small', { class: 'muted' });
    const items = state.catalog.agencies.map((a) => h('label', { class: 'check agency', 'data-name': a.name.toLowerCase() },
      h('input', { type: 'checkbox', name: 'agency_ids', value: String(a.id) }), a.name,
      already.has(a.id) ? h('small', { class: 'muted' }, ' (sudah dipanjangkan)') : null));
    const list = h('div', { class: 'agency-pick' }, items);
    const sync = () => {
      const q = search.value.trim().toLowerCase();
      for (const el of items) el.hidden = q !== '' && !el.dataset.name.includes(q) && !el.querySelector('input').checked;
      const n = list.querySelectorAll('input:checked').length;
      count.textContent = n ? `${n} agensi dipilih` : 'Pilih satu atau lebih agensi.';
    };
    search.addEventListener('input', sync);
    list.addEventListener('change', sync);
    sync();
    return opForm(c, [
      h('div', { class: 'field wide', 'data-field': 'agency_ids' }, h('label', {}, 'Agensi'), search, list, count, h('small', { class: 'err' })),
      field('Mesej kepada agensi', textarea('note', 'Mohon pihak tuan/puan mengambil tindakan sewajarnya ke atas aduan ini dan memaklumkan status tindakan kepada kami.', { rows: 3 }),
        { help: 'Dihantar dari ' + 'info@' + cfg.staff_domain + ' bersama butiran aduan, lokasi dan gambar. Maklumat pengadu (nama, IC, telefon, e-mel) tidak dihantar.' }),
      ...followupFields(c),
    ], 'Hantar ke agensi', (f) => ({ op: 'refer', fields: Object.assign({
      agency_ids: [...f.querySelectorAll('input[name=agency_ids]:checked')].map((x) => Number(x.value)), note: val(f, 'note'),
    }, followupValues(f)) }));
  }

  function selfForm(c) {
    return opForm(c, [
      h('p', {}, 'Kes ini akan diuruskan sendiri oleh pegawai kes tanpa dipanjangkan ke agensi.'),
      field('Rancangan tindakan (pilihan)', textarea('note', '', { rows: 3 })),
      ...followupFields(c),
    ], 'Simpan keputusan', (f) => ({ op: 'self', fields: Object.assign({ note: val(f, 'note') }, followupValues(f)) }));
  }

  function needInfoForm(c) {
    return opForm(c, [
      h('p', {}, 'Kes kekal terbuka dan ditanda "Perlu maklumat lanjut" sehingga maklumat diperoleh.'),
      field('Maklumat yang diperlukan', textarea('note', '', { rows: 3 }), { help: 'Contoh: Lokasi tepat dan gambar terkini. Hubungi pengadu jika perlu (sekurang-kurangnya 10 aksara).' }),
      ...followupFields(c),
    ], 'Simpan keputusan', (f) => ({ op: 'need_info', fields: Object.assign({ note: val(f, 'note') }, followupValues(f)) }));
  }

  function agencyUpdateForm(c) {
    const ref = select('referral_id', c.referrals.map((r) => ({ value: r.id, label: `${r.agency_name} (${r.status})` })), c.referrals[0]?.id);
    const status = select('agency_status', state.catalog.agency_statuses.map((x) => ({ value: x, label: x })), c.referrals[0]?.status);
    const refNo = h('input', { name: 'reference_no', maxlength: 100, value: c.referrals[0]?.reference_no || '' });
    ref.addEventListener('change', () => {
      const r = c.referrals.find((x) => String(x.id) === ref.value);
      status.value = r?.status || status.value;
      refNo.value = r?.reference_no || '';
    });
    return opForm(c, [
      field('Agensi', ref),
      field('Status agensi', status),
      field('No. rujukan agensi (pilihan)', refNo),
      field('Catatan (pilihan)', textarea('note', '', { rows: 2 }), { help: 'Contoh: maklum balas yang diterima daripada agensi.' }),
    ], 'Simpan status', (f) => ({ op: 'agency_update', fields: {
      referral_id: Number(ref.value), agency_status: status.value, reference_no: refNo.value, note: val(f, 'note'),
    } }));
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
    // Accounts waiting for a role go first, so they are not missed.
    users.sort((a, b) => Number(!!b.needs_role) - Number(!!a.needs_role));
    const rows = users.map((u) => h('tr', { class: u.active ? '' : 'inactive' },
      h('td', { 'data-label': 'Nama', class: 'main-col' }, h('span', { class: 'title' }, u.name), h('small', {}, u.email),
        u.email_ok ? null : h('small', { class: 'bad-text' }, `Bukan e-mel @${cfg.staff_domain}. Sila tukar.`)),
      h('td', { 'data-label': 'Peranan' }, ROLE[u.role], u.site_admin ? h('small', {}, 'Pentadbir laman') : null),
      h('td', { 'data-label': 'Kategori', class: 'cats' }, policyText(u)),
      h('td', { 'data-label': 'Kes terbuka' }, String(u.open_cases)),
      h('td', { 'data-label': 'Status' }, u.needs_role ? h('span', { class: 'badge st-diterima' }, 'Perlu peranan')
        : h('span', { class: 'badge ' + (u.active ? 'st-ok' : 'st-off') }, u.active ? 'Aktif' : 'Tidak aktif')),
      h('td', { 'data-label': '' }, u.site_admin ? h('small', { class: 'muted' }, 'Urus dalam WordPress')
        : h('button', { class: 'btn ' + (u.needs_role ? '' : 'ghost ') + 'small', type: 'button', onclick: () => open(u) }, u.needs_role ? 'Beri peranan' : 'Ubah'))));

    view.replaceChildren(
      h('div', { class: 'page-head' }, h('h1', {}, 'Pengguna'), h('button', { class: 'btn', type: 'button', onclick: () => open(null) }, '+ Tambah pengguna')),
      panel,
      h('div', { class: 'card table-wrap' }, h('table', { class: 'users' },
        h('thead', {}, h('tr', {}, ['Nama', 'Peranan', 'Kategori dibenarkan', 'Kes terbuka', 'Status', ''].map((t) => h('th', { scope: 'col' }, t)))),
        h('tbody', {}, rows))),
      h('p', { class: 'muted small' }, 'Beritahu kata laluan kepada pengguna secara terus (bukan melalui kumpulan chat). Mereka boleh menukarnya sendiri di halaman Profil. Jika terlupa, tekan Ubah dan tetapkan kata laluan baru. Akaun yang dinyahaktifkan tidak boleh log masuk, tetapi sejarah kesnya kekal.'));
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
      if (form.elements.password.value) body.password = form.elements.password.value;
      if (r === 'officer') {
        body.policy = { mode: form.elements.mode.value, codes: [...form.querySelectorAll('input[name=codes]:checked')].map((x) => x.value) };
      }
      if (!isNew) body.active = form.elements.active.checked;
      await submitting(form, async () => {
        try {
          const saved = await api(isNew ? '/admin/users' : '/admin/users/' + u.id, { method: 'POST', body });
          toast(isNew ? `Akaun ${saved.email} dicipta. Beritahu kata laluannya kepada ${saved.name}.`
            : body.password ? `Kata laluan baru ${saved.name} disimpan. Mereka perlu log masuk semula dengan kata laluan ini.` : 'Perubahan disimpan.');
          await loadCatalog();
          await usersView();
        } catch (err) { showErrors(form, err); }
      });
    } },
      h('h2', {}, isNew ? 'Tambah pengguna' : 'Ubah ' + u.name),
      h('div', { class: 'two' },
        field('Nama penuh', h('input', { name: 'name', value: u?.name || '', autocomplete: 'off', required: true })),
        field('E-mel', h('input', { name: 'email', type: 'email', value: u?.email || '', autocomplete: 'off', required: true, placeholder: `nama@${cfg.staff_domain}` }),
          { help: `Mesti e-mel @${cfg.staff_domain}.` })),
      (() => {
        const pw = passwordInput('password', isNew ? { required: true } : {});
        return h('div', { class: 'field', 'data-field': 'password' },
          h('label', { for: pw.input.id }, isNew ? 'Kata laluan' : 'Kata laluan baru'), pw.wrap,
          h('small', { class: 'help' }, isNew ? PW_HELP : 'Isi hanya jika pengguna terlupa kata laluan. ' + PW_HELP + ' Pengguna akan dilog keluar dari semua peranti.'),
          h('small', { class: 'err' }));
      })(),
      roleBox, policyBox,
      isNew ? null : h('label', { class: 'check field wide' }, h('input', { type: 'checkbox', name: 'active', checked: u.active || u.needs_role }), 'Akaun aktif (boleh log masuk)'),
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

  /* ---------- profile ---------- */

  function profileView() {
    const me = state.me;
    const cur = passwordInput('current', { autocomplete: 'current-password', required: true, minlength: null });
    const pw = passwordInput('password', { required: true });
    const again = passwordInput('again', { required: true });
    const pwField = (label, p, name, help) => h('div', { class: 'field', 'data-field': name },
      h('label', { for: p.input.id }, label), p.wrap, help ? h('small', { class: 'help' }, help) : null, h('small', { class: 'err' }));
    const form = h('form', { class: 'card user-form', novalidate: true, onsubmit: async (e) => {
      e.preventDefault();
      showErrors(form);
      if (pw.input.value !== again.input.value) {
        showErrors(form, { message: 'Kata laluan tidak sepadan.', fields: { again: 'Taip semula kata laluan baru yang sama.' } });
        return;
      }
      await submitting(form, async () => {
        try {
          await api('/admin/me/password', { method: 'POST', body: { current: cur.input.value, password: pw.input.value } });
          form.replaceChildren(h('h2', {}, 'Kata laluan ditukar'), h('p', {}, 'Peranti lain telah dilog keluar. Memuatkan semula portal…'));
          // The login cookie changed, so the page needs a fresh security token.
          setTimeout(() => location.reload(), 1500);
        } catch (err) { showErrors(form, err); }
      });
    } },
      h('h2', {}, 'Tukar kata laluan'),
      h('div', { class: 'two' },
        pwField('Kata laluan semasa', cur, 'current'),
        h('div'),
        pwField('Kata laluan baru', pw, 'password', PW_HELP),
        pwField('Taip semula kata laluan baru', again, 'again')),
      h('p', { class: 'form-error', role: 'alert' }),
      h('div', { class: 'form-actions' }, h('button', { class: 'btn', type: 'submit' }, 'Tukar kata laluan')));
    view.replaceChildren(
      h('div', { class: 'page-head' }, h('h1', {}, 'Profil')),
      h('div', { class: 'card' },
        h('dl', { class: 'facts' },
          h('dt', {}, 'Nama'), h('dd', {}, me.name),
          h('dt', {}, 'E-mel'), h('dd', {}, me.email || '—'),
          h('dt', {}, 'Peranan'), h('dd', {}, ROLE[me.role])),
        h('p', { class: 'muted small' }, me.can.manage ? 'Nama dan e-mel diubah di halaman Pengguna.' : 'Untuk menukar nama atau e-mel, hubungi Super Admin.')),
      form,
      h('p', { class: 'muted small' }, 'Lupa kata laluan? Minta Super Admin tetapkan kata laluan baru untuk anda.'));
  }

  /* ---------- notifications ---------- */

  function setUnread(n) {
    const el = bell?.querySelector('.count');
    if (!el) return;
    el.hidden = !n;
    el.textContent = n > 99 ? '99+' : String(n);
    bell.setAttribute('aria-label', n ? `Notifikasi (${n} belum dibaca)` : 'Notifikasi');
    if ('setAppBadge' in navigator) (n ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {});
  }
  async function refreshUnread() {
    try { setUnread((await api('/admin/notifications', { params: { count: 1 } })).unread); } catch { /* offline: try again later */ }
  }

  async function notificationsView() {
    view.replaceChildren(h('p', { class: 'loading' }, 'Memuatkan notifikasi…'));
    const data = await api('/admin/notifications');
    setUnread(data.unread);
    const open = async (n) => {
      if (!n.read) setUnread((await api('/admin/notifications/read', { method: 'POST', body: { ids: [n.id] } })).unread);
      location.hash = n.case_id ? '#/kes/' + n.case_id : '#/notifikasi';
    };
    const items = data.items.map((n) => h('li', { class: n.read ? '' : 'unread' },
      h('button', { type: 'button', class: 'notif', onclick: () => open(n) },
        h('strong', {}, n.title), n.body ? h('span', {}, n.body) : null, h('small', { class: 'muted' }, formatDate(n.created_at)))));
    view.replaceChildren(
      h('div', { class: 'page-head' }, h('h1', {}, 'Notifikasi'),
        data.unread ? h('button', { class: 'btn ghost', type: 'button', onclick: async () => {
          setUnread((await api('/admin/notifications/read', { method: 'POST', body: {} })).unread);
          notificationsView();
        } }, 'Tandakan semua dibaca') : null),
      await deviceCard(),
      items.length ? h('ul', { class: 'card notif-list' }, items) : h('div', { class: 'card empty' }, 'Tiada notifikasi lagi.'));
  }

  const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  let installPrompt = null;
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); installPrompt = e; });

  /** Turns on push for this device, and helps install the portal as an app. */
  async function deviceCard() {
    const card = h('section', { class: 'card device' }, h('h2', {}, 'Notifikasi di peranti ini'));
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    const reg = supported ? await navigator.serviceWorker.getRegistration(cfg.sw).catch(() => null) : null;
    const sub = reg ? await reg.pushManager.getSubscription().catch(() => null) : null;
    const line = (text, cls) => h('p', { class: cls || '' }, text);

    if (!standalone()) {
      if (installPrompt) {
        card.append(line('Pasang portal ini sebagai aplikasi di telefon untuk buka terus dari skrin utama.'),
          h('button', { class: 'btn ghost', type: 'button', onclick: async () => { installPrompt.prompt(); await installPrompt.userChoice; installPrompt = null; notificationsView(); } }, 'Pasang aplikasi'));
      } else if (isIos()) {
        card.append(line('iPhone/iPad: tekan butang Kongsi (petak dengan anak panah ke atas) di Safari, pilih "Add to Home Screen", kemudian buka MyAduan dari skrin utama untuk aktifkan notifikasi.', 'help-box'));
      } else {
        card.append(line('Untuk pasang di telefon: buka menu pelayar (⋮) dan pilih "Install app" atau "Add to Home screen".', 'muted small'));
      }
    }
    if (!supported) {
      if (!isIos() || standalone()) card.append(line('Pelayar ini tidak menyokong notifikasi push. Notifikasi tetap dipaparkan di loceng dalam portal.', 'muted'));
      return card;
    }
    if (Notification.permission === 'denied') {
      card.append(line('Notifikasi disekat untuk laman ini. Benarkan notifikasi dalam tetapan pelayar/telefon, kemudian muat semula.', 'warn-text'));
      return card;
    }
    if (sub) {
      card.append(line('Notifikasi push aktif di peranti ini.', 'ok-text'),
        h('button', { class: 'btn ghost small', type: 'button', onclick: async () => {
          await api('/admin/push', { method: 'DELETE', body: { endpoint: sub.endpoint } }).catch(() => {});
          await sub.unsubscribe();
          toast('Notifikasi push dimatikan untuk peranti ini.');
          notificationsView();
        } }, 'Matikan di peranti ini'));
    } else {
      card.append(line('Aktifkan untuk terima notifikasi di telefon/komputer walaupun portal tidak dibuka.'),
        h('button', { class: 'btn', type: 'button', onclick: async (e) => {
          e.currentTarget.disabled = true;
          try {
            await enablePush();
            toast('Notifikasi push diaktifkan.');
          } catch (err) {
            toast(err.message || 'Tidak dapat aktifkan notifikasi.', 'bad');
          }
          notificationsView();
        } }, 'Aktifkan notifikasi'));
    }
    return card;
  }

  /** This browser's push subscription, if any. */
  async function currentSubscription() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return null;
    const reg = await navigator.serviceWorker.getRegistration(cfg.sw).catch(() => null);
    return reg ? reg.pushManager.getSubscription().catch(() => null) : null;
  }

  /** A shared browser must not keep getting the previous user's notifications. */
  async function logout(e) {
    e.preventDefault();
    try {
      const sub = await Promise.race([currentSubscription(), new Promise((r) => setTimeout(() => r(null), 1500))]);
      if (sub) {
        await api('/admin/push', { method: 'DELETE', body: { endpoint: sub.endpoint } }).catch(() => {});
        await sub.unsubscribe().catch(() => {});
      }
    } finally {
      location.href = cfg.logout;
    }
  }

  async function enablePush() {
    if (await Notification.requestPermission() !== 'granted') throw new Error('Kebenaran notifikasi tidak diberi.');
    const reg = await navigator.serviceWorker.register(cfg.sw);
    await navigator.serviceWorker.ready;
    const { public_key: key } = await api('/admin/push');
    const raw = atob(key.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (key.length % 4)) % 4));
    const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: Uint8Array.from(raw, (ch) => ch.charCodeAt(0)) });
    await api('/admin/push', { method: 'POST', body: sub.toJSON() });
  }

  /* ---------- agencies ---------- */

  async function agenciesView() {
    view.replaceChildren(h('p', { class: 'loading' }, 'Memuatkan agensi…'));
    const list = await api('/admin/agencies');
    const panel = h('div', { class: 'user-panel' });
    const open = (a) => {
      panel.replaceChildren(agencyForm(a, panel));
      panel.scrollIntoView({ block: 'start' });
      panel.querySelector('input')?.focus();
    };
    const filter = h('input', { type: 'search', placeholder: 'Cari agensi atau e-mel', 'aria-label': 'Cari agensi' });
    const rows = list.map((a) => h('tr', { class: a.active ? '' : 'inactive', 'data-q': (a.name + ' ' + a.email + ' ' + a.officer).toLowerCase() },
      h('td', { 'data-label': 'Agensi', class: 'main-col' }, h('span', { class: 'title' }, a.name), a.officer ? h('small', {}, a.officer) : null),
      h('td', { 'data-label': 'E-mel' }, a.email, a.cc.length ? h('small', {}, 'CC: ' + a.cc.join(', ')) : null),
      h('td', { 'data-label': 'Status' }, h('span', { class: 'badge ' + (a.active ? 'st-ok' : 'st-off') }, a.active ? 'Aktif' : 'Tidak aktif')),
      h('td', { 'data-label': '' }, h('button', { class: 'btn ghost small', type: 'button', onclick: () => open(a) }, 'Ubah'))));
    filter.addEventListener('input', () => {
      const q = filter.value.trim().toLowerCase();
      for (const r of rows) r.hidden = q !== '' && !r.dataset.q.includes(q);
    });
    view.replaceChildren(
      h('div', { class: 'page-head' }, h('h1', {}, 'Agensi'), h('button', { class: 'btn', type: 'button', onclick: () => open(null) }, '+ Tambah agensi')),
      h('p', { class: 'muted small' }, `Kes boleh dipanjangkan kepada agensi aktif. E-mel dihantar ke e-mel utama dengan salinan (CC) kepada alamat tambahan. ${list.length} agensi.`),
      panel,
      h('div', { class: 'card' }, filter),
      h('div', { class: 'card table-wrap' }, h('table', { class: 'users' },
        h('thead', {}, h('tr', {}, ['Agensi', 'E-mel', 'Status', ''].map((t) => h('th', { scope: 'col' }, t)))),
        h('tbody', {}, rows))));
  }

  /* ---------- e-mail log ---------- */

  async function mailView(query) {
    view.replaceChildren(h('p', { class: 'loading' }, 'Memuatkan e-mel…'));
    const data = await api('/admin/mail', { params: { state: query.state || '' } });
    const label = { pending: 'Belum dihantar', sent: 'Dihantar', failed: 'Gagal', bounced: 'Bounce' };
    const chips = h('div', { class: 'chips' },
      h('a', { class: 'chip' + (!query.state ? ' active' : ''), href: '#/emel' }, 'Semua'),
      Object.entries(label).map(([k, t]) => h('a', { class: 'chip' + (query.state === k ? ' active' : ''), href: '#/emel?state=' + k }, `${t} (${data.counts[k] || 0})`)));
    const rows = data.items.map((m) => h('tr', {},
      h('td', { 'data-label': 'Masa' }, formatDate(m.created_at), h('small', {}, m.kind)),
      h('td', { 'data-label': 'E-mel', class: 'main-col' }, h('span', { class: 'title' }, m.subject), h('small', {}, 'Kepada: ' + m.recipient)),
      h('td', { 'data-label': 'Kes' }, m.case_id ? h('a', { href: '#/kes/' + m.case_id }, m.reference || '#' + m.case_id) : '—'),
      h('td', { 'data-label': 'Status' }, mailBadge(m.status),
        m.status.state === 'sent' ? h('small', {}, formatDate(m.status.at)) : null,
        m.status.error ? h('small', { class: 'bad-text' }, m.status.error) : null),
      h('td', { 'data-label': '' }, m.can_retry ? h('button', { class: 'btn ghost small', type: 'button', onclick: async (e) => {
        e.currentTarget.disabled = true;
        try { await api(`/admin/mail/${m.id}/retry`, { method: 'POST' }); toast('E-mel dimasukkan semula untuk dihantar.'); await mailView(query); } catch (err) { toast(err.message, 'error'); e.currentTarget.disabled = false; }
      } }, 'Hantar semula') : null)));

    const run = h('button', { class: 'btn ghost', type: 'button', onclick: async () => {
      run.disabled = true;
      try {
        const r = await api('/admin/mail/run', { method: 'POST' });
        toast(`${r.sent} e-mel dihantar. ${r.found} bounce/balasan baru ditemui.`);
        await mailView(query);
      } catch (err) { toast(err.message, 'error'); run.disabled = false; }
    } }, 'Hantar & semak sekarang');

    view.replaceChildren(
      h('div', { class: 'page-head' }, h('h1', {}, 'E-mel'), run),
      h('p', { class: 'muted small' }, `Semua e-mel MyAduan dihantar dari ${data.from}. "Dihantar" bermaksud pelayan e-mel kita telah menghantarnya keluar; tiada sistem e-mel boleh memastikan e-mel dibaca. Jika penerima menolaknya, status bertukar kepada Bounce (perlu sambungan mailbox di bawah).`),
      chips,
      h('div', { class: 'card table-wrap' }, rows.length ? h('table', { class: 'users mail' },
        h('thead', {}, h('tr', {}, ['Masa', 'E-mel', 'Kes', 'Status', ''].map((t) => h('th', { scope: 'col' }, t)))),
        h('tbody', {}, rows)) : h('p', { class: 'muted' }, 'Tiada e-mel.')),
      h('div', { class: 'two-cards' }, mailboxCard(data.mailbox, () => mailView(query)), testMailCard(() => mailView(query))));
  }

  function mailboxCard(box, reload) {
    const pw = passwordInput('pass', { autocomplete: 'off', minlength: null, placeholder: box.configured ? 'Kosongkan untuk kekal' : '' });
    const form = h('form', { class: 'card user-form', novalidate: true, onsubmit: async (e) => {
      e.preventDefault();
      showErrors(form);
      await submitting(form, async () => {
        try {
          await api('/admin/mailbox', { method: 'POST', body: { host: form.elements.host.value, port: form.elements.port.value, user: form.elements.user.value, pass: pw.input.value } });
          toast('Mailbox disambung. Bounce dan balasan agensi akan disemak setiap 5 minit.');
          await reload();
        } catch (err) { showErrors(form, err); }
      });
    } },
      h('h2', {}, 'Mailbox untuk bounce & balasan agensi'),
      h('p', { class: 'small ' + (box.configured ? (box.last_error ? 'bad-text' : '') : 'muted') },
        !box.configured ? 'Belum disambung. Tanpa ini, bounce dan balasan agensi tidak dapat dikesan.'
          : box.last_error ? 'Ralat semakan terakhir: ' + box.last_error
            : `Disambung. Semakan terakhir: ${box.last_check ? formatDate(box.last_check) : 'belum'} · ${box.found} bounce · ${box.replies} balasan agensi.`),
      h('div', { class: 'two' },
        field('Pelayan IMAP', h('input', { name: 'host', value: box.host, autocomplete: 'off' })),
        field('Port', h('input', { name: 'port', value: String(box.port), inputmode: 'numeric', autocomplete: 'off' }))),
      field('Mailbox', h('input', { name: 'user', type: 'email', value: box.user, autocomplete: 'off' }), { help: 'Mailbox penghantar, biasanya info@myaduan.my.' }),
      h('div', { class: 'field', 'data-field': 'pass' }, h('label', { for: pw.input.id }, 'Kata laluan mailbox'), pw.wrap,
        h('small', { class: 'help' }, 'Disimpan secara rahsia (disulitkan) dan tidak dipaparkan semula. Jangan kongsi dalam chat.'), h('small', { class: 'err' })),
      h('p', { class: 'form-error', role: 'alert' }),
      h('div', { class: 'form-actions' }, h('button', { class: 'btn', type: 'submit' }, box.configured ? 'Simpan' : 'Sambung mailbox'),
        box.configured ? h('button', { class: 'btn ghost', type: 'button', onclick: async () => {
          if (!confirm('Putuskan sambungan mailbox? Bounce dan balasan agensi tidak akan dikesan lagi.')) return;
          await api('/admin/mailbox', { method: 'DELETE' }); toast('Sambungan mailbox diputuskan.'); await reload();
        } }, 'Putuskan') : null));
    return form;
  }

  function testMailCard(reload) {
    const out = h('p', { class: 'small', 'aria-live': 'polite' });
    const form = h('form', { class: 'card user-form', novalidate: true, onsubmit: async (e) => {
      e.preventDefault();
      showErrors(form);
      out.textContent = '';
      await submitting(form, async () => {
        try {
          const r = await api('/admin/mail/test', { method: 'POST', body: { to: form.elements.to.value } });
          out.replaceChildren(mailBadge(r.status), r.status?.error ? ' ' + r.status.error : '');
          await reload();
        } catch (err) { showErrors(form, err); }
      });
    } },
      h('h2', {}, 'Hantar e-mel ujian'),
      field('Kepada', h('input', { name: 'to', type: 'email', autocomplete: 'off', placeholder: 'contoh: nama@gmail.com' })),
      h('p', { class: 'form-error', role: 'alert' }), out,
      h('div', { class: 'form-actions' }, h('button', { class: 'btn', type: 'submit' }, 'Hantar ujian')));
    return form;
  }

  function agencyForm(a, panel) {
    const isNew = !a;
    const form = h('form', { class: 'card user-form', novalidate: true, onsubmit: async (e) => {
      e.preventDefault();
      showErrors(form);
      const body = { name: form.elements.name.value, officer: form.elements.officer.value, email: form.elements.email.value, cc: form.elements.cc.value, active: form.elements.active.checked };
      await submitting(form, async () => {
        try {
          await api(isNew ? '/admin/agencies' : '/admin/agencies/' + a.id, { method: 'POST', body });
          toast(isNew ? 'Agensi ditambah.' : 'Perubahan disimpan.');
          await loadCatalog();
          await agenciesView();
        } catch (err) { showErrors(form, err); }
      });
    } },
      h('h2', {}, isNew ? 'Tambah agensi' : 'Ubah ' + a.name),
      field('Nama agensi', h('input', { name: 'name', value: a?.name || '', maxlength: 150, required: true }), { wide: true }),
      field('Pegawai / jawatan', h('input', { name: 'officer', value: a?.officer || '', maxlength: 200 }), { help: 'Dipaparkan pada baris "Kepada" dalam e-mel.' }),
      h('div', { class: 'two' },
        field('E-mel utama', h('input', { name: 'email', type: 'email', value: a?.email || '', required: true })),
        field('E-mel tambahan (CC)', h('input', { name: 'cc', value: (a?.cc || []).join(', ') }), { help: 'Pisahkan dengan koma.' })),
      h('label', { class: 'check field wide' }, h('input', { type: 'checkbox', name: 'active', checked: isNew || a.active }), 'Aktif (boleh dipilih bila panjangkan kes)'),
      h('p', { class: 'form-error', role: 'alert' }),
      h('div', { class: 'form-actions' },
        h('button', { class: 'btn', type: 'submit' }, isNew ? 'Tambah agensi' : 'Simpan'),
        h('button', { class: 'btn ghost', type: 'button', onclick: () => panel.replaceChildren() }, 'Batal')));
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
    refreshUnread();
    setInterval(() => { if (document.visibilityState === 'visible') refreshUnread(); }, 60000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refreshUnread(); });
    if ('serviceWorker' in navigator && cfg.sw) {
      // Keeps the worker current; push itself is turned on from the Notifikasi page.
      navigator.serviceWorker.register(cfg.sw).catch(() => {});
      // A subscription left in this browser belongs to whoever is signed in now.
      currentSubscription().then((sub) => sub && api('/admin/push', { method: 'POST', body: sub.toJSON() })).catch(() => {});
      navigator.serviceWorker.addEventListener('message', (e) => {
        if (e.data?.type === 'myaduan-push') {
          refreshUnread();
          if (parseHash().parts[0] === 'notifikasi') notificationsView();
        }
      });
    }
  }

  boot();
})();
