// Browser check of the admin portal against a disposable site.
// Usage: BASE_URL=http://localhost:8080 SHOTS=/tmp/shots node tests/e2e/portal.mjs
// Expects accounts e2e_super (Super Admin) and e2e_officer (Pegawai, category A04), password in E2E_PASS.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { deflateSync } from 'node:zlib';

const BASE = process.env.BASE_URL || 'http://localhost:8080';
const SHOTS = process.env.SHOTS || '/tmp/myaduan-shots';
const PASS = process.env.E2E_PASS || 'Ujian-12345';
mkdirSync(SHOTS, { recursive: true });

function png(w, h) {
  const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
  const crc = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => {
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type), data]);
    const c = Buffer.alloc(4); c.writeUInt32BE(crc(td));
    return Buffer.concat([len, td, c]);
  };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  const row = Buffer.concat([Buffer.from([0]), Buffer.alloc(w * 3, 0).fill(Buffer.from([212, 160, 23]))]);
  const raw = Buffer.concat(Array.from({ length: h }, () => row));
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

function assert(cond, msg) { if (!cond) { throw new Error('GAGAL: ' + msg); } console.log('ok -', msg); }

// A fresh complaint with a photo, sent the way the public form sends it.
const fd = new FormData();
const fields = {
  client_key: [...crypto.getRandomValues(new Uint8Array(32))].map((b) => b.toString(16).padStart(2, '0')).join(''),
  name: 'Siti binti Ahmad', ic: '880512-10-5432', phone: '012-987 6543', consent: 'true',
  details: 'Lampu jalan di depan sekolah rosak sejak minggu lepas. Gelap dan bahaya waktu malam.',
  location: 'Jalan Sungai Tua, depan SK Sungai Tua',
};
for (const [k, v] of Object.entries(fields)) fd.append(k, v);
fd.append('images[]', new Blob([png(800, 600)], { type: 'image/png' }), 'lampu.png');
const created = await (await fetch(BASE + '/wp-json/myaduan/v1/complaints', { method: 'POST', body: fd })).json();
assert(/^GOM-/.test(created.reference || ''), 'aduan ujian dicipta: ' + created.reference);

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const errors = [];
const watch = (page) => {
  page.on('pageerror', (e) => errors.push(e.message));
  // A 422 is expected once: the test submits an empty close form on purpose.
  page.on('console', (m) => m.type() === 'error' && !m.text().includes('status of 422') && errors.push(m.text()));
};

async function login(page, user, start) {
  await page.goto(BASE + start);
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(PASS);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/\/adminaduan\//);
  await page.locator('main#view h1').first().waitFor();
}
const statusOf = (page) => page.locator('.case-head .badge').first().textContent();

/* ---- Super Admin: triage and assign (desktop) ---- */
const desk = await browser.newPage({ viewport: { width: 1366, height: 900 } });
watch(desk);
await login(desk, 'e2e_super', '/adminaduan/');
assert(await desk.locator('h1').textContent() === 'Senarai kes', 'Super Admin masuk ke senarai kes');
assert(await desk.locator('.nav a', { hasText: 'Pengguna' }).isVisible(), 'menu Pengguna untuk Super Admin');
await desk.locator('.stat', { hasText: 'Belum dikelaskan' }).click();
await desk.locator(`a.ref`, { hasText: created.reference }).waitFor();
await desk.screenshot({ path: `${SHOTS}/p1-senarai.png`, fullPage: true });

await desk.locator('a.ref', { hasText: created.reference }).click();
await desk.locator('.case-head').waitFor();
assert(await desk.getByText('Siti binti Ahmad').isVisible(), 'nama pengadu dipapar untuk Super Admin');
assert(await desk.locator('.photos img').evaluate((img) => img.complete && img.naturalWidth > 0), 'gambar aduan dimuatkan dari storan peribadi');
await desk.locator('[data-op=screen]').click();
await desk.locator('select[name=category_code]').selectOption('A04');
await desk.locator('select[name=dun_slug]').selectOption('sungai-tua');
await desk.locator('select[name=priority]').selectOption('SEGERA');
const ownerOptions = await desk.locator('select[name=owner_id] option').allTextContents();
assert(ownerOptions.includes('Encik Pegawai Lampu'), 'pegawai kategori Lampu Jalan disenaraikan');
await desk.locator('select[name=owner_id]').selectOption({ label: 'Encik Pegawai Lampu' });
assert(await desk.locator('.op-form button[type=submit]').textContent() === 'Simpan & tugaskan', 'butang bertukar kepada Simpan & tugaskan');
await desk.locator('.op-form button[type=submit]').click();
await desk.locator('.timeline li', { hasText: 'Ditugaskan' }).waitFor();
assert(await statusOf(desk) === 'DALAM SEMAKAN', 'status DALAM SEMAKAN selepas ditugaskan');
await desk.screenshot({ path: `${SHOTS}/p2-ditugaskan.png`, fullPage: true });

/* ---- Super Admin: add a user ---- */
await desk.locator('.nav a', { hasText: 'Pengguna' }).click();
await desk.getByRole('button', { name: '+ Tambah pengguna' }).click();
const email = `baru${Date.now()}@myaduan.test`;
await desk.locator('.user-form input[name=name]').fill('Cik Pegawai Jalan');
await desk.locator('.user-form input[name=email]').fill(email);
await desk.locator('.user-form input[name=mode][value=selected]').check();
await desk.locator('.user-form input[name=codes][value=A01]').check();
await desk.screenshot({ path: `${SHOTS}/p3-tambah-pengguna.png`, fullPage: true });
await desk.getByRole('button', { name: 'Cipta akaun' }).click();
await desk.locator('#toast', { hasText: 'Akaun dicipta' }).waitFor();
await desk.locator('tbody tr', { hasText: email }).waitFor();
assert((await desk.locator('tbody tr', { hasText: email }).textContent()).includes('Jalan & Infrastruktur'), 'pengguna baru disenaraikan dengan kategorinya');
await desk.screenshot({ path: `${SHOTS}/p4-pengguna.png`, fullPage: true });

/* ---- Officer on a phone: accept, act, ask to close ---- */
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
const phone = await ctx.newPage();
watch(phone);
await login(phone, 'e2e_officer', '/wp-login.php');
assert(new URL(phone.url()).pathname === '/adminaduan/', 'pegawai terus ke portal selepas log masuk');
assert(await phone.locator('.nav a', { hasText: 'Pengguna' }).count() === 0, 'tiada menu Pengguna untuk pegawai');
await phone.locator('.stat', { hasText: 'Kes saya' }).click();
await phone.locator('a.ref', { hasText: created.reference }).click();
await phone.locator('.case-head').waitFor();
await phone.screenshot({ path: `${SHOTS}/p5-pegawai-kes.png`, fullPage: true });
await phone.locator('.op-form button[type=submit]', { hasText: 'Terima tugasan' }).click();
await phone.locator('.timeline li', { hasText: 'Tugasan diterima' }).waitFor();
assert(await statusOf(phone) === 'DALAM TINDAKAN', 'status DALAM TINDAKAN selepas diterima');

await phone.locator('[data-op=action]').click();
await phone.locator('textarea[name=note]').fill('Lawatan tapak. Aduan dipanjangkan kepada MPS untuk ganti lampu.');
await phone.locator('input[name=next_action]').fill('Semak semula selepas MPS ganti lampu');
await phone.locator('.op-form button[type=submit]').click();
await phone.locator('.timeline li', { hasText: 'Tindakan direkod' }).waitFor();

await phone.locator('[data-op=request_close]').click();
await phone.locator('.op-form button[type=submit]').click();
await phone.locator('.form-error', { hasText: 'sebab penutupan' }).waitFor();
assert(true, 'ralat dipapar bila borang penutupan kosong');
await phone.locator('select[name=closure_reason]').selectOption('Selesai');
await phone.locator('textarea[name=resolution]').fill('MPS telah menggantikan lampu jalan pada 21 Oktober.');
await phone.locator('textarea[name=public_draft]').fill('Lampu jalan telah dibaiki oleh MPS. Terima kasih atas aduan anda.');
await phone.locator('.op-form button[type=submit]').click();
await phone.locator('.timeline li', { hasText: 'Mohon penutupan' }).waitFor();
assert(await statusOf(phone) === 'MENUNGGU PENGESAHAN', 'status MENUNGGU PENGESAHAN');
await phone.screenshot({ path: `${SHOTS}/p6-pegawai-mohon-tutup.png`, fullPage: true });

await phone.goto(BASE + '/wp-admin/index.php');
await phone.waitForURL(/\/adminaduan\//);
assert(true, 'pegawai yang buka wp-admin dihantar ke portal');

/* ---- Super Admin approves ---- */
await desk.goto(BASE + '/adminaduan/#/kes?status=MENUNGGU%20PENGESAHAN');
await desk.locator('a.ref', { hasText: created.reference }).click();
await desk.locator('.op-form button[type=submit]', { hasText: 'Sahkan & tutup kes' }).waitFor();
assert((await desk.locator('textarea[name=public_message]').inputValue()).includes('MPS'), 'draf mesej awam diisi automatik');
await desk.locator('.op-form button[type=submit]').click();
await desk.locator('.timeline li', { hasText: 'Disahkan & ditutup' }).waitFor();
assert(await statusOf(desk) === 'DITUTUP', 'kes DITUTUP selepas disahkan');
await desk.screenshot({ path: `${SHOTS}/p7-ditutup.png`, fullPage: true });

/* ---- The complainant sees the public message ---- */
const track = await (await fetch(BASE + '/wp-json/myaduan/v1/track', {
  method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ query: created.reference }),
})).json();
const tracked = (track.items || track.results || [track]).find((x) => x.reference === created.reference) || {};
assert(tracked.status === 'DITUTUP', 'semak status awam menunjukkan DITUTUP');

assert(errors.length === 0, 'tiada ralat JavaScript' + (errors.length ? ': ' + errors.join(' | ') : ''));
await browser.close();
console.log('SEMUA LULUS', created.reference);
