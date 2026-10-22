/* MyAduan public form and status check. No build step, no dependencies. */
(() => {
  'use strict';

  const DRAFT_KEY = 'myaduan-borang-v1';
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

  class Base extends HTMLElement {
    connectedCallback() {
      if (this.shadowRoot) return;
      const root = this.attachShadow({ mode: 'open' });
      root.append(h('link', { rel: 'stylesheet', href: this.dataset.css }));
      this.api = (this.dataset.api || '').replace(/\/$/, '');
      if (this.dataset.logo) {
        root.append(h('a', { class: 'brand', href: this.dataset.home || '/' },
          h('img', { src: this.dataset.logo, alt: 'Logo MyAduan', width: '52', height: '52' }),
          h('span', {}, 'MyAduan', h('small', {}, 'Sistem Aduan Parlimen Gombak'))));
      }
      this.card = h('div', { class: 'card' });
      root.append(this.card);
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
  }

  class Borang extends Base {
    render() {
      this.state = this.loadDraft();
      this.files = [];
      this.showForm();
    }

    loadDraft() {
      try {
        const saved = JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null');
        if (saved && saved.key) return saved;
      } catch (e) { /* storage blocked */ }
      return { key: newKey(), fields: {}, coords: null };
    }

    saveDraft() {
      try { sessionStorage.setItem(DRAFT_KEY, JSON.stringify(this.state)); } catch (e) { /* ignore */ }
    }

    clearDraft() {
      try { sessionStorage.removeItem(DRAFT_KEY); } catch (e) { /* ignore */ }
      this.state = { key: newKey(), fields: {}, coords: null };
    }

    field(name, label, hint, control) {
      const err = h('div', { class: 'err', id: `err-${name}`, 'aria-live': 'polite' });
      const wrap = h('div', { class: 'field', 'data-field': name },
        h('label', { for: `f-${name}` }, label, hint ? h('span', { class: 'hint' }, ' ', hint) : null),
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
        if (name === 'details') this.counter.textContent = `${el.value.trim().length} / 5000`;
      });
      this.inputs[name] = el;
      return el;
    }

    showForm(banner) {
      this.errs = {};
      this.inputs = {};
      const f = this.state.fields;
      this.counter = h('div', { class: 'counter' }, `${(f.details || '').trim().length} / 5000`);
      this.gpsRow = h('div', { class: 'row' });
      this.thumbs = h('div', { class: 'thumbs' });
      this.banner = h('div', { role: 'alert' });
      const fileInput = h('input', {
        type: 'file', accept: 'image/jpeg,image/png,image/webp', multiple: true, hidden: true,
        onchange: (e) => this.addFiles(e.target.files),
      });
      this.submitBtn = h('button', { class: 'primary', type: 'submit' }, 'Hantar aduan');

      const form = h('form', { novalidate: true, onsubmit: (e) => { e.preventDefault(); this.submit(); } },
        h('h2', {}, 'Borang Aduan'),
        h('p', { class: 'lead' }, 'Parlimen Gombak. Isi 5 maklumat di bawah. Gambar dan lokasi GPS adalah pilihan.'),
        this.dataset.emergency ? h('div', { class: 'emergency' }, this.dataset.emergency) : null,
        this.banner,
        this.field('name', 'Nama penuh', null, this.input('name', { type: 'text', autocomplete: 'name', maxlength: 150, required: true })),
        this.field('ic', 'No. kad pengenalan', '(untuk semak status)', this.input('ic', { type: 'text', inputmode: 'numeric', autocomplete: 'off', placeholder: '900101-14-5678', maxlength: 14, required: true })),
        this.field('phone', 'No. telefon', null, this.input('phone', { type: 'tel', autocomplete: 'tel', placeholder: '012-345 6789', maxlength: 20, required: true })),
        this.field('details', 'Butiran aduan', '(apa masalahnya, sejak bila)', h('div', {}, this.input('details', { multiline: true, maxlength: 5000, required: true }), this.counter)),
        this.field('location', 'Lokasi kejadian', '(nama jalan, taman, mercu tanda)', h('div', {}, this.input('location', { type: 'text', maxlength: 500, required: true }), this.gpsRow)),
        this.field('images', 'Gambar', `(pilihan, maksimum ${MAX_IMAGES})`, h('div', {},
          h('button', { type: 'button', onclick: () => fileInput.click() }, '📷 Tambah gambar'), fileInput, this.thumbs)),
        h('input', { class: 'hp', type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }),
        this.field('consent', '', null, h('label', { class: 'consent' },
          this.consentBox = h('input', { type: 'checkbox', id: 'f-consent', onchange: () => this.setError('consent', '') }),
          h('span', {}, 'Saya mengesahkan maklumat ini benar dan bersetuju maklumat peribadi saya digunakan untuk mengurus aduan ini.',
            this.dataset.privacy ? [' ', h('a', { href: this.dataset.privacy, target: '_blank', rel: 'noopener' }, 'Notis privasi')] : null))),
        this.submitBtn);
      this.card.replaceChildren(form);
      this.renderGps();
      this.renderThumbs();
      if (banner) this.showBanner(banner);
    }

    renderGps() {
      const c = this.state.coords;
      this.gpsRow.replaceChildren(c
        ? h('span', { class: 'chip' }, '📍 Lokasi GPS dilampirkan ', h('button', { type: 'button', onclick: () => { this.state.coords = null; this.saveDraft(); this.renderGps(); } }, 'Buang'))
        : h('button', { type: 'button', onclick: () => this.locate() }, '📍 Guna lokasi semasa'));
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
      // Submit waits for this, so a photo still being resized is never left out.
      this.pending = (this.pending || Promise.resolve()).then(() => this.processFiles(list));
      return this.pending;
    }

    async processFiles(list) {
      this.setError('images', '');
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

    validate() {
      const f = this.state.fields;
      const errors = {};
      if ((f.name || '').trim().length < 3) errors.name = 'Nama penuh diperlukan.';
      if ((f.ic || '').replace(/\D/g, '').length !== 12) errors.ic = 'No. IC mesti 12 digit.';
      if ((f.phone || '').replace(/\D/g, '').length < 9) errors.phone = 'No. telefon tidak sah.';
      if ((f.details || '').trim().length < 20) errors.details = 'Terangkan aduan sekurang-kurangnya 20 aksara.';
      if ((f.location || '').trim().length < 5) errors.location = 'Lokasi kejadian diperlukan.';
      if (!this.consentBox.checked) errors.consent = 'Sila tandakan pengesahan ini.';
      return errors;
    }

    applyErrors(errors) {
      Object.entries(errors).forEach(([k, v]) => this.setError(k, v));
      const first = Object.keys(errors)[0];
      const el = this.inputs[first] || (first === 'consent' ? this.consentBox : null);
      if (el) el.focus();
    }

    async submit() {
      this.showBanner('');
      const errors = this.validate();
      if (Object.keys(errors).length) return this.applyErrors(errors);

      if (this.pending) {
        this.submitBtn.disabled = true;
        this.submitBtn.textContent = 'Memproses gambar…';
        await this.pending;
      }

      const fd = new FormData();
      fd.append('client_key', this.state.key);
      for (const k of ['name', 'ic', 'phone', 'details', 'location']) fd.append(k, this.state.fields[k] || '');
      fd.append('consent', 'true');
      fd.append('website', this.card.querySelector('.hp').value);
      if (this.state.coords) { fd.append('lat', this.state.coords.lat); fd.append('lng', this.state.coords.lng); }
      this.files.forEach((f) => fd.append('images[]', f.file, f.file.name));

      this.submitBtn.disabled = true;
      this.submitBtn.textContent = 'Menghantar…';
      try {
        const { status, data } = await this.post('/complaints', fd);
        if (status === 201 && data && data.reference) {
          this.files.forEach((f) => URL.revokeObjectURL(f.url));
          this.files = [];
          this.clearDraft();
          return this.showSuccess(data.reference);
        }
        if (status === 422 && data && data.data && data.data.fields) {
          this.applyErrors(data.data.fields);
          this.showBanner(data.message);
        } else {
          this.showBanner((data && data.message) || 'Aduan belum dapat dihantar. Cuba lagi.');
        }
      } catch (e) {
        this.showBanner('Sambungan gagal. Tekan "Hantar aduan" sekali lagi. Aduan anda tidak akan berganda.');
      } finally {
        this.submitBtn.disabled = false;
        this.submitBtn.textContent = 'Hantar aduan';
      }
    }

    showSuccess(ref) {
      const copy = h('button', { type: 'button' }, 'Salin nombor rujukan');
      copy.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(ref); copy.textContent = 'Disalin ✓'; } catch (e) { copy.textContent = ref; }
      });
      this.card.replaceChildren(h('div', { class: 'success', role: 'status' },
        h('div', { class: 'tick', 'aria-hidden': 'true' }, '✓'),
        h('h2', {}, 'Aduan diterima'),
        h('p', { class: 'lead' }, 'Simpan nombor rujukan ini. Anda boleh semak status dengan nombor ini atau No. IC.'),
        h('div', { class: 'ref', 'data-ref': ref }, ref),
        h('div', { class: 'row', style: 'justify-content:center' }, copy,
          h('button', { type: 'button', onclick: () => this.showForm() }, 'Hantar aduan lain'))));
      this.card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  class Semak extends Base {
    render() {
      this.results = h('div', { class: 'results', 'aria-live': 'polite' });
      this.query = h('input', { type: 'text', id: 'q', placeholder: 'GOM-20261021-XXXXXXXXXX atau 900101-14-5678', autocomplete: 'off' });
      this.btn = h('button', { class: 'primary', type: 'submit' }, 'Semak status');
      this.card.replaceChildren(h('form', { onsubmit: (e) => { e.preventDefault(); this.search(); } },
        h('h2', {}, 'Semak Status Aduan'),
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
