/* MyAduan public form and status check. No build step, no dependencies. */
(() => {
  'use strict';

  const DRAFT_KEY = 'myaduan-borang-v3';
  const MAX_IMAGES = 3;
  const MAX_BYTES = 5 * 1024 * 1024;
  const RESIZE_SIDE = 2000;
  const STATUS_TEXT = {
    'DITERIMA': 'Aduan diterima dan menunggu saringan.',
    'DALAM SEMAKAN': 'Aduan sedang disemak dan ditugaskan kepada pegawai.',
    'DALAM TINDAKAN': 'Pegawai sedang mengambil tindakan.',
    'MENUNGGU PENGESAHAN': 'Tindakan selesai dan menunggu pengesahan penutupan.',
    'DITUTUP': 'Aduan telah ditutup.',
  };

  /** Tiny element builder: h('div', {class: 'x'}, 'text', child). Text is always set safely. */
  function h(tag, attrs, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === false || v == null) continue;
      if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (v === true) el.setAttribute(k, '');
      else el.setAttribute(k, v);
    }
    for (const c of children.flat()) {
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

  function formatDate(iso) {
    if (!iso) return '';
    return new Intl.DateTimeFormat('ms-MY', {
      timeZone: 'Asia/Kuala_Lumpur', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
  }

  function formatIc(v) {
    const d = v.replace(/\D/g, '').slice(0, 12);
    return [d.slice(0, 6), d.slice(6, 8), d.slice(8)].filter(Boolean).join('-');
  }

  /** Shrinks big phone photos before upload; the server re-encodes again anyway. */
  async function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || typeof createImageBitmap !== 'function') return file;
    try {
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const scale = Math.min(1, RESIZE_SIDE / Math.max(bmp.width, bmp.height));
      if (scale === 1 && file.size <= 1.5 * 1024 * 1024) { bmp.close(); return file; }
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(bmp.width * scale);
      canvas.height = Math.round(bmp.height * scale);
      canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
      bmp.close();
      const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
      const blob = await new Promise((r) => canvas.toBlob(r, type, 0.85));
      if (!blob || blob.size >= file.size) return file;
      return new File([blob], file.name.replace(/\.\w+$/, '') + (type === 'image/png' ? '.png' : '.jpg'), { type });
    } catch (e) {
      return file;
    }
  }

  const DUNS = 'Sungai Tua · Gombak Setia · Hulu Kelang';
  const STAGES = ['Lokasi & gambar', 'Maklumat anda'];

  /** Small line icons (stroke follows currentColor). */
  const ICONS = {
    arrow: 'M5 12h14M13 6l6 6-6 6',
    back: 'M19 12H5M11 6l-6 6 6 6',
    check: 'M5 12.5l4.5 4.5L19 7',
    shield: 'M12 3l7 3v6c0 4.4-3 7.7-7 9-4-1.3-7-4.6-7-9V6z M9 12l2 2 4-4',
    lock: 'M7 11V8a5 5 0 0 1 10 0v3 M5 11h14v10H5z',
    clip: 'M9 4h6v3H9z M7 5H5v16h14V5h-2 M9 12h6M9 16h4',
    camera: 'M4 8h3l2-3h6l2 3h3v11H4z M12 17a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z',
    pin: 'M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z M12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
    alert: 'M12 3l10 18H2z M12 10v5 M12 18h.01',
    phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z',
    search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z M21 21l-5-5',
  };
  function icon(name, size = 20) {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', size);
    svg.setAttribute('height', size);
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('class', 'ico');
    const path = document.createElementNS(ns, 'path');
    path.setAttribute('d', ICONS[name]);
    svg.append(path);
    return svg;
  }

  /** Shared frame: top bar with the logo, dark side panel, and the white work area with the Aduan / Semak switch. */
  class Base extends HTMLElement {
    connectedCallback() {
      if (this.shadowRoot) return;
      const root = this.attachShadow({ mode: 'open' });
      root.append(h('link', { rel: 'stylesheet', href: this.dataset.css }));
      this.api = (this.dataset.api || '').replace(/\/$/, '');
      this.side = h('div', { class: 'side-body' });
      this.main = h('div', { class: 'main-body' });
      this.foot = h('div', { class: 'main-foot' });
      const tab = (href, label, on) => h('a', { href: href || '#', 'aria-current': on ? 'page' : null }, label);
      root.append(h('div', { class: 'app' },
        h('header', { class: 'topbar' },
          h('a', { class: 'brand', href: this.dataset.home || '/' },
            this.dataset.logo ? h('img', { src: this.dataset.logo, alt: '', width: '48', height: '48' }) : null,
            h('span', {}, 'MYADUAN', h('small', {}, 'PARLIMEN GOMBAK'))),
          h('span', { class: 'tag' }, 'Saluran aduan rasmi')),
        h('div', { class: 'workspace' },
          h('aside', { class: 'side' },
            h('div', {},
              h('p', { class: 'eyebrow gold' }, 'SALURAN ADUAN MASYARAKAT'),
              h('h2', {}, 'Suara anda.', h('br'), 'Perhatian kami.'),
              h('p', { class: 'side-intro' }, this.mode === 'form' ? 'Dua langkah sahaja: lokasi dan gambar, kemudian maklumat anda.' : 'Semak perkembangan aduan anda pada bila-bila masa.')),
            this.side,
            h('div', { class: 'side-foot' }, icon('shield', 21), h('p', {}, 'Maklumat anda digunakan untuk pengurusan dan tindakan aduan sahaja.'))),
          h('main', { class: 'main' },
            h('nav', { class: 'mode', 'aria-label': 'Pilihan perkhidmatan' },
              tab(this.dataset.form, 'Buat Aduan', this.mode === 'form'),
              tab(this.dataset.track, 'Semak Status Aduan', this.mode === 'track')),
            this.main, this.foot)),
        h('div', { class: 'bottom-note' }, 'Parlimen Gombak', h('span', {}, ' • '), DUNS)));
      this.render();
    }

    async post(path, body) {
      const isForm = body instanceof FormData;
      const res = await fetch(this.api + path, {
        method: 'POST',
        body: isForm ? body : JSON.stringify(body),
        headers: isForm ? {} : { 'Content-Type': 'application/json' },
        credentials: 'omit',
      });
      let data = null;
      try { data = await res.json(); } catch (e) { /* non-JSON error page */ }
      return { status: res.status, data };
    }

    top() {
      this.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const h1 = this.main.querySelector('h1');
      if (h1) h1.focus({ preventScroll: true });
    }
  }

  /** One question per step, in the style of the first MyAduan form. */
  class Borang extends Base {
    get mode() { return 'form'; }

    render() {
      this.state = this.loadDraft();
      this.files = [];
      // Two pages only: where and what (with photos), then who. DUN and taman are filled in by the Super Admin in the portal.
      this.steps = [
        { stage: 0, kicker: 'Lokasi & gambar', q: 'Di mana masalahnya?', lead: 'Tulis lokasi dan sertakan gambar. Pegawai kami akan uruskan selebihnya.', body: () => this.stepWhere(), check: () => this.checkWhere() },
        { stage: 1, kicker: 'Maklumat anda', q: 'Siapa yang membuat aduan?', lead: 'No. kad pengenalan digunakan untuk semak status. Nombor telefon untuk pegawai menghubungi anda.', body: () => this.stepWho(), check: () => this.checkFields(['name', 'ic', 'phone', 'email', 'consent']) },
      ];
      if (!(this.state.step >= 0 && this.state.step < this.steps.length)) this.state.step = 0;
      this.show();
    }

    loadDraft() {
      try {
        const saved = JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null');
        if (saved && saved.key && saved.fields) return { step: 0, ...saved, restored: true };
      } catch (e) { /* storage blocked */ }
      return { key: newKey(), fields: {}, coords: null, step: 0 };
    }

    saveDraft() {
      const { restored, ...keep } = this.state;
      try { sessionStorage.setItem(DRAFT_KEY, JSON.stringify(keep)); } catch (e) { /* ignore */ }
    }

    clearDraft() {
      try { sessionStorage.removeItem(DRAFT_KEY); } catch (e) { /* ignore */ }
      this.state = { key: newKey(), fields: {}, coords: null, step: 0 };
    }

    // ---- frame ----

    renderStages() {
      const current = this.steps[this.state.step].stage;
      this.side.replaceChildren(h('nav', { class: 'stages', 'aria-label': 'Peringkat aduan' },
        STAGES.map((name, i) => h('div', { class: 'stage' + (i === current ? ' active' : '') + (i < current ? ' done' : '') },
          h('span', { class: 'dot' }, i < current ? icon('check', 16) : String(i + 1).padStart(2, '0')),
          h('div', {}, name, i === current ? h('small', {}, 'Sedang diisi') : null)))));
    }

    show() {
      const step = this.state.step;
      this.errs = {};
      this.inputs = {};
      this.renderStages();
      this.banner = h('div', { role: 'alert' });
      const total = this.steps.length;
      const progress = Math.round(((step + 1) / total) * 100);
      const head = h('div', { class: 'form-top' },
        h('span', {}, this.steps[step].kicker),
        h('span', {}, `${step + 1} daripada ${total}`));
      const bar = h('div', { class: 'progress', role: 'progressbar', 'aria-label': 'Kemajuan borang', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(progress) },
        h('span', { style: `width:${progress}%` }));
      const notice = this.state.restored && (step > 0 || Object.keys(this.state.fields).length)
        ? h('div', { class: 'notice', role: 'status' }, 'Draf dalam tab ini dipulihkan. Pilih semula gambar jika ada.')
        : null;
      this.state.restored = false;

      const s = this.steps[step];
      const body = h('div', { class: 'question' },
        h('p', { class: 'eyebrow red' }, `LANGKAH ${String(step + 1).padStart(2, '0')}`),
        h('h1', { tabindex: '-1' }, s.q),
        h('p', { class: 'lead' }, s.lead),
        s.body());
      this.main.replaceChildren(...[head, bar, notice, this.banner, body].filter(Boolean),
        h('input', { class: 'hp', type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true', value: this.hp || '', oninput: (e) => { this.hp = e.target.value; } }));
      this.renderFoot();
    }

    renderFoot(busyText) {
      const step = this.state.step;
      const last = step === this.steps.length - 1;
      const label = last ? 'Hantar aduan' : 'Seterusnya';
      this.nextBtn = h('button', { class: 'primary', type: 'button', disabled: !!busyText, onclick: () => this.next() }, busyText || label, busyText ? null : icon('arrow'));
      this.foot.replaceChildren(
        h('div', { class: 'actions' },
          step > 0 ? h('button', { class: 'back', type: 'button', disabled: !!busyText, onclick: () => this.back() }, icon('back'), h('span', {}, 'Kembali')) : h('span'),
          this.nextBtn),
        h('p', { class: 'privacy' }, icon('lock', 12), ' Privasi anda diutamakan. Maklumat peribadi digunakan untuk pengurusan aduan sahaja.'));
    }

    go(step) {
      this.state.step = step;
      this.saveDraft();
      this.show();
      this.top();
    }

    back() {
      this.go(this.state.step - 1);
    }

    async next() {
      const step = this.state.step;
      const ok = await this.steps[step].check();
      if (!ok) return;
      if (step === this.steps.length - 1) return this.submit();
      this.go(step + 1);
    }

    // ---- steps ----

    stepWhere() {
      this.gpsRow = h('div', { class: 'row' });
      this.thumbs = h('div', { class: 'thumbs' });
      const pick = (capture) => h('label', { class: 'secondary' }, capture ? 'Ambil gambar' : 'Pilih gambar',
        h('input', {
          type: 'file', accept: 'image/jpeg,image/png,image/webp', multiple: !capture, capture: capture ? 'environment' : null,
          onchange: (e) => { this.addFiles(Array.from(e.target.files)); e.target.value = ''; },
        }));
      this.counter = h('div', { class: 'counter' }, `${(this.state.fields.details || '').trim().length} / 5000`);
      const wrap = h('div', {},
        h('p', { class: 'urgent' }, icon('alert', 18), h('span', {}, 'Kecemasan seperti kebakaran atau jenayah? ', h('a', { href: 'tel:999' }, 'Hubungi 999'), ' dahulu.')),
        this.field('location', 'Lokasi', null, h('div', {},
          this.input('location', { multiline: true, rows: 2, maxlength: 500, placeholder: 'Contoh: Hadapan surau, Jalan Melati 3, Taman Melati' }),
          this.gpsRow)),
        this.field('images', 'Gambar', `(sehingga ${MAX_IMAGES} gambar, maksimum 5 MB setiap satu)`, h('div', {},
          h('div', { class: 'upload' }, icon('camera', 35), h('h3', {}, 'Tambah gambar masalah'),
            h('div', { class: 'upload-actions' }, pick(false), pick(true))),
          this.thumbs)),
        this.field('details', 'Apa masalahnya?', '(pilihan jika ada gambar)', h('div', {},
          this.input('details', { multiline: true, rows: 3, maxlength: 5000, placeholder: 'Contoh: Lampu jalan tidak menyala sejak Isnin.' }),
          this.counter)));
      this.renderGps();
      this.renderThumbs();
      return wrap;
    }

    async checkWhere() {
      if (this.pending) {
        this.renderFoot('Memproses gambar…');
        await this.pending;
        this.renderFoot();
      }
      return this.checkFields(['location', 'details']);
    }

    stepWho() {
      return h('div', {},
        this.field('name', 'Nama', '(nama ringkas pun boleh)', this.input('name', { type: 'text', autocomplete: 'name', maxlength: 150, placeholder: 'Contoh: Kak Siti' })),
        this.field('ic', 'No. kad pengenalan', null, this.input('ic', { type: 'text', inputmode: 'numeric', autocomplete: 'off', placeholder: '900101-14-5678', maxlength: 14 })),
        this.field('phone', 'No. telefon', null, this.input('phone', { type: 'tel', autocomplete: 'tel', placeholder: '012-345 6789', maxlength: 20 })),
        this.field('email', 'E-mel', '(pilihan, untuk terima nombor rujukan)', this.input('email', { type: 'email', autocomplete: 'email', inputmode: 'email', placeholder: 'nama@contoh.com', maxlength: 190 })),
        this.field('consent', '', null, h('label', { class: 'consent' }, this.box('consent'),
          h('span', {}, 'Saya mengesahkan maklumat ini benar dan bersetuju maklumat peribadi saya digunakan untuk mengurus aduan ini.',
            this.dataset.privacy ? [' ', h('a', { href: this.dataset.privacy, target: '_blank', rel: 'noopener' }, 'Notis privasi')] : null))));
    }

    // ---- fields ----

    field(name, label, hint, control) {
      const err = h('div', { class: 'err', id: `err-${name}`, 'aria-live': 'polite' });
      const wrap = h('div', { class: 'field', 'data-field': name },
        label ? h('label', { for: `f-${name}` }, label, hint ? h('span', { class: 'hint' }, ' ', hint) : null) : null,
        control, err);
      this.errs[name] = { wrap, err };
      return wrap;
    }

    input(name, attrs) {
      const el = h(attrs.multiline ? 'textarea' : 'input', {
        id: `f-${name}`, name, 'aria-describedby': `err-${name}`, ...attrs, multiline: null,
      });
      el.value = this.state.fields[name] || '';
      el.addEventListener('input', () => {
        if (name === 'ic') el.value = formatIc(el.value);
        this.state.fields[name] = el.value;
        this.saveDraft();
        this.setError(name, '');
        if (name === 'details' && this.counter) this.counter.textContent = `${el.value.trim().length} / 5000`;
      });
      el.addEventListener('keydown', (e) => { if (e.key === 'Enter' && el.tagName === 'INPUT') { e.preventDefault(); this.next(); } });
      this.inputs[name] = el;
      return el;
    }

    box(name) {
      const el = h('input', { type: 'checkbox', id: `f-${name}`, checked: !!this.state.fields[name] });
      el.addEventListener('change', () => { this.state.fields[name] = el.checked; this.saveDraft(); this.setError(name, ''); });
      this.inputs[name] = el;
      return el;
    }

    renderGps() {
      const c = this.state.coords;
      this.gpsRow.replaceChildren(c
        ? h('span', { class: 'chip' }, icon('pin', 16), ` Koordinat ${c.lat}, ${c.lng} `, h('button', { type: 'button', class: 'link', onclick: () => { this.state.coords = null; this.saveDraft(); this.renderGps(); } }, 'Buang'))
        : h('button', { type: 'button', class: 'secondary', onclick: () => this.locate() }, icon('pin', 18), ' Gunakan lokasi semasa'));
    }

    locate() {
      if (!navigator.geolocation) return this.setError('location', 'Peranti ini tidak menyokong GPS.');
      this.gpsRow.replaceChildren(h('span', { class: 'chip' }, 'Mendapatkan lokasi…'));
      navigator.geolocation.getCurrentPosition(
        (p) => { this.state.coords = { lat: p.coords.latitude.toFixed(6), lng: p.coords.longitude.toFixed(6) }; this.saveDraft(); this.renderGps(); },
        () => { this.renderGps(); this.setError('location', 'Lokasi GPS tidak dapat diperoleh. Tulis lokasi secara manual.'); },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    }

    addFiles(list) {
      // Next and submit wait for this, so a photo still being resized is never left out.
      this.pending = (this.pending || Promise.resolve()).then(() => this.processFiles(list));
      return this.pending;
    }

    async processFiles(list) {
      this.setError('images', '');
      this.setError('details', '');
      for (const file of Array.from(list)) {
        if (this.files.length >= MAX_IMAGES) { this.setError('images', `Maksimum ${MAX_IMAGES} gambar.`); break; }
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { this.setError('images', 'Hanya JPG, PNG atau WebP.'); continue; }
        const small = await shrink(file);
        if (small.size > MAX_BYTES) { this.setError('images', 'Gambar mesti kurang daripada 5 MB.'); continue; }
        this.files.push({ file: small, url: URL.createObjectURL(small) });
      }
      this.renderThumbs();
    }

    renderThumbs() {
      if (!this.thumbs) return;
      this.thumbs.replaceChildren(...this.files.map((f, i) => h('div', { class: 'thumb' },
        h('img', { src: f.url, alt: `Gambar ${i + 1}` }),
        h('button', { type: 'button', 'aria-label': `Buang gambar ${i + 1}`, onclick: () => { URL.revokeObjectURL(f.url); this.files.splice(i, 1); this.renderThumbs(); } }, '×'))));
    }

    setError(name, msg) {
      const e = this.errs[name];
      if (!e) return;
      e.err.textContent = msg || '';
      e.wrap.classList.toggle('invalid', !!msg);
    }

    showBanner(msg) {
      this.banner.replaceChildren(msg ? h('div', { class: 'banner error' }, msg) : '');
    }

    rules() {
      const f = this.state.fields;
      return {
        location: (f.location || '').trim().length < 5 ? 'Tulis lokasi masalah.' : '',
        details: !(f.details || '').trim() && !this.files.length ? 'Sertakan gambar atau tulis ringkas apa masalahnya.' : '',
        name: (f.name || '').trim().length < 2 ? 'Tulis nama anda.' : '',
        ic: (f.ic || '').replace(/\D/g, '').length !== 12 ? 'No. IC mesti 12 digit.' : '',
        phone: (f.phone || '').replace(/\D/g, '').length < 9 ? 'No. telefon tidak sah.' : '',
        email: (f.email || '').trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.trim()) ? 'Alamat e-mel tidak sah. Kosongkan jika tiada.' : '',
        consent: !f.consent ? 'Sila tandakan pengesahan ini.' : '',
      };
    }

    checkFields(names) {
      const all = this.rules();
      const errors = {};
      names.forEach((n) => { if (all[n]) errors[n] = all[n]; });
      return Object.keys(errors).length ? this.fail(errors) : true;
    }

    fail(errors) {
      Object.entries(errors).forEach(([k, v]) => this.setError(k, v));
      const el = this.inputs[Object.keys(errors)[0]];
      if (el) el.focus();
      return false;
    }

    /** Step that holds a field, so a server error can send the user back to it. */
    stepOf(field) {
      return ['location', 'images', 'details'].includes(field) ? 0 : this.steps.length - 1;
    }

    async submit() {
      this.showBanner('');
      const all = this.rules();
      const wrong = Object.keys(all).find((k) => all[k]);
      if (wrong) { this.go(this.stepOf(wrong)); return this.fail({ [wrong]: all[wrong] }); }

      if (this.pending) { this.renderFoot('Memproses gambar…'); await this.pending; }

      const fd = new FormData();
      fd.append('client_key', this.state.key);
      for (const k of ['name', 'ic', 'phone', 'details', 'location']) fd.append(k, this.state.fields[k] || '');
      fd.append('email', (this.state.fields.email || '').trim());
      fd.append('consent', 'true');
      fd.append('website', this.hp || '');
      if (this.state.coords) { fd.append('lat', this.state.coords.lat); fd.append('lng', this.state.coords.lng); }
      this.files.forEach((f) => fd.append('images[]', f.file, f.file.name));

      this.renderFoot('Sedang menghantar…');
      try {
        const { status, data } = await this.post('/complaints', fd);
        if (status === 201 && data && data.reference) {
          this.files.forEach((f) => URL.revokeObjectURL(f.url));
          this.files = [];
          this.clearDraft();
          return this.showSuccess(data.reference);
        }
        this.renderFoot();
        if (status === 422 && data && data.data && data.data.fields) {
          const fields = data.data.fields;
          const first = Object.keys(fields)[0];
          if (this.stepOf(first) !== this.state.step) this.go(this.stepOf(first));
          this.fail(fields);
          this.showBanner(data.message);
        } else {
          this.showBanner((data && data.message) || 'Aduan belum dapat dihantar. Cuba lagi.');
        }
      } catch (e) {
        this.renderFoot();
        this.showBanner('Sambungan gagal. Tekan "Hantar aduan" sekali lagi. Aduan anda tidak akan berganda.');
      }
    }

    showSuccess(ref) {
      this.side.replaceChildren(h('nav', { class: 'stages' }, STAGES.map((name) => h('div', { class: 'stage done' }, h('span', { class: 'dot' }, icon('check', 16)), h('div', {}, name)))));
      const copy = h('button', { type: 'button', class: 'secondary' }, 'Salin nombor rujukan');
      copy.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(ref); copy.textContent = 'Disalin ✓'; } catch (e) { copy.textContent = ref; }
      });
      this.main.replaceChildren(
        h('div', { class: 'form-top' }, h('span', {}, 'Selesai'), h('span', {}, '100%')),
        h('div', { class: 'progress' }, h('span', { style: 'width:100%' })),
        h('div', { class: 'question success', role: 'status' },
          h('div', { class: 'tick' }, icon('check', 40)),
          h('p', { class: 'eyebrow red' }, 'ADUAN TELAH DIREKODKAN'),
          h('h1', { tabindex: '-1' }, 'Terima kasih. Aduan anda diterima.'),
          h('p', { class: 'lead' }, 'Simpan nombor rujukan ini. Anda boleh semak status dengan nombor ini atau No. kad pengenalan.'),
          h('div', { class: 'reference' }, h('small', {}, 'Nombor rujukan aduan'), h('strong', { 'data-ref': ref }, ref)),
          h('div', { class: 'row center' }, copy,
            this.dataset.track ? h('a', { class: 'secondary', href: this.dataset.track }, 'Semak Status Aduan') : null)));
      this.foot.replaceChildren(h('div', { class: 'actions' }, h('span'),
        h('button', { class: 'primary', type: 'button', onclick: () => { this.show(); this.top(); } }, 'Buat aduan baharu', icon('arrow'))));
      this.top();
    }
  }

  class Semak extends Base {
    get mode() { return 'track'; }

    render() {
      this.side.replaceChildren(h('div', { class: 'side-tips' },
        h('p', {}, h('strong', {}, 'Apa yang anda perlukan'), h('br'), 'Nombor rujukan (contoh GOM-20261021-XXXXXXXXXX) atau No. kad pengenalan yang digunakan semasa membuat aduan.'),
        h('p', {}, h('strong', {}, 'Apa yang dipaparkan'), h('br'), 'Status terkini, tarikh dan mesej daripada pegawai. Butiran peribadi tidak dipaparkan.')));
      this.results = h('div', { class: 'results', 'aria-live': 'polite' });
      this.query = h('input', { type: 'text', id: 'q', placeholder: 'GOM-20261021-XXXXXXXXXX atau 900101-14-5678', autocomplete: 'off' });
      this.btn = h('button', { class: 'primary', type: 'submit' }, 'Semak status', icon('arrow'));
      this.main.replaceChildren(h('form', { class: 'question', onsubmit: (e) => { e.preventDefault(); this.search(); } },
        h('p', { class: 'eyebrow red' }, 'SEMAK STATUS'),
        h('h1', { tabindex: '-1' }, 'Di mana aduan saya?'),
        h('p', { class: 'lead' }, 'Masukkan nombor rujukan atau No. kad pengenalan.'),
        h('div', { class: 'field' }, h('label', { for: 'q' }, 'Nombor rujukan / No. IC'), this.query),
        this.btn),
      this.results);
    }

    async search() {
      const q = this.query.value.trim();
      if (q.length < 6) return this.results.replaceChildren(h('div', { class: 'banner error' }, 'Masukkan nombor rujukan atau No. IC.'));
      this.btn.disabled = true;
      try {
        const { status, data } = await this.post('/track', { query: q });
        if (status !== 200) return this.results.replaceChildren(h('div', { class: 'banner error' }, (data && data.message) || 'Carian gagal.'));
        if (!data.items.length) return this.results.replaceChildren(h('p', {}, 'Tiada aduan ditemui.'));
        this.results.replaceChildren(...data.items.map((r) => h('div', { class: 'result' },
          h('div', { class: 'top' }, h('code', {}, r.reference), h('span', { class: 'status', 'data-s': r.status }, r.status)),
          h('div', {}, STATUS_TEXT[r.status] || ''),
          h('div', { class: 'meta' }, `Diterima ${formatDate(r.created_at)} · Dikemas kini ${formatDate(r.updated_at)}`),
          r.public_message ? h('div', { class: 'msg' }, r.public_message) : null)));
      } catch (e) {
        this.results.replaceChildren(h('div', { class: 'banner error' }, 'Sambungan gagal. Cuba lagi.'));
      } finally {
        this.btn.disabled = false;
      }
    }
  }

  if (!customElements.get('myaduan-borang')) customElements.define('myaduan-borang', Borang);
  if (!customElements.get('myaduan-semak')) customElements.define('myaduan-semak', Semak);
})();
