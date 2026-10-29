// Browser check of the public form and status page against a disposable site.
// Usage: BASE_URL=http://localhost:8080 SHOTS=/tmp/shots node tests/e2e/borang.mjs
// Expects pages /aduan/ with [myaduan_borang] and /semak/ with [myaduan_semak].
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { deflateSync } from 'node:zlib';

const BASE = process.env.BASE_URL || 'http://localhost:8080';
const SHOTS = process.env.SHOTS || '/tmp/myaduan-shots';
mkdirSync(SHOTS, { recursive: true });

/** Minimal valid PNG (solid colour) so the test needs no fixture files. */
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
  const row = Buffer.concat([Buffer.from([0]), Buffer.alloc(w * 3, 0).fill(Buffer.from([179, 18, 43]))]);
  const raw = Buffer.concat(Array.from({ length: h }, () => row));
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

function assert(cond, msg) { if (!cond) { throw new Error('GAGAL: ' + msg); } console.log('ok -', msg); }

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const page = await browser.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

await page.goto(BASE + '/aduan/');
await page.evaluate(() => sessionStorage.clear());
await page.reload();
const form = page.locator('myaduan-borang');
const next = () => form.locator('button.primary').click();
await form.locator('h1').waitFor();
assert((await form.locator('h1').textContent()).includes('Di mana'), 'terus ke page 1: lokasi & gambar');
assert(await form.locator('.urgent a[href="tel:999"]').isVisible(), 'pautan 999 untuk kecemasan dipapar');

// Page 1: location and a photo. A description is optional once a photo is there.
await next();
assert(await form.locator('#err-location').textContent() !== '', 'ralat bila lokasi kosong');
assert(await form.locator('#err-details').textContent() !== '', 'perlu gambar atau keterangan');
await page.screenshot({ path: `${SHOTS}/1-page1-ralat.png`, fullPage: true });
await form.locator('#f-location').fill('Jalan Sungai Tua, depan SK Sungai Tua');
// Go on straight after picking a large photo: the form must wait for resizing, not drop the photo.
await form.locator('input[type=file]').first().setInputFiles({ name: 'lampu.png', mimeType: 'image/png', buffer: png(4000, 3000) });
await next();
await form.locator('#f-name').waitFor();
assert((await form.locator('h1').textContent()).includes('Siapa'), 'page 2: maklumat pengadu');
await form.locator('button.back').click();
await form.locator('.thumb img').waitFor();
await page.screenshot({ path: `${SHOTS}/2-page1-gambar.png`, fullPage: true });
assert(await form.locator('.thumb').count() === 1, 'gambar kekal bila kembali ke page 1');
await next();

// Page 2: name, IC, phone, optional e-mail, consent.
await form.locator('#f-name').fill('Siti');
await form.locator('#f-ic').pressSequentially('880512105432');
assert(await form.locator('#f-ic').inputValue() === '880512-10-5432', 'IC diformat automatik');
await form.locator('#f-phone').fill('012-987 6543');

// Reload keeps the draft for this tab (photos are not kept).
await page.reload();
await form.locator('#f-name').waitFor();
assert(await form.locator('#f-name').inputValue() === 'Siti', 'draf kekal selepas muat semula');
await form.locator('button.back').click();
await form.locator('input[type=file]').first().setInputFiles({ name: 'lampu.png', mimeType: 'image/png', buffer: png(1200, 900) });
await form.locator('.thumb img').waitFor();
await next();
await next();
assert(await form.locator('#err-consent').textContent() !== '', 'mesti tanda pengesahan');
await form.locator('#f-consent').check();
await page.screenshot({ path: `${SHOTS}/3-page2.png`, fullPage: true });
const submitted = page.waitForResponse((r) => r.url().includes('/myaduan/v1/complaints'));
await next();
assert((await (await submitted).json()).images === 1, 'gambar dihantar bersama aduan');
const refEl = form.locator('[data-ref]');
await refEl.waitFor({ timeout: 15000 });
const ref = (await refEl.textContent()).trim();
assert(/^GOM-\d{8}-[A-F0-9]{10}$/.test(ref), 'nombor rujukan dipapar: ' + ref);
await page.screenshot({ path: `${SHOTS}/5-berjaya.png`, fullPage: true });

await page.goto(BASE + '/semak/');
const semak = page.locator('myaduan-semak');
await semak.locator('#q').fill('880512-10-5432');
await semak.getByRole('button', { name: 'Semak status' }).click();
await semak.locator('.result').first().waitFor();
assert((await semak.locator('.result code').first().textContent()) === ref, 'semak dengan IC jumpa aduan');
assert(!(await semak.locator('.results').textContent()).includes('Siti'), 'nama pengadu tidak didedahkan');
await page.screenshot({ path: `${SHOTS}/6-semak.png`, fullPage: true });

const fontFamily = await form.evaluate((el) => getComputedStyle(el.shadowRoot.querySelector('.app')).fontFamily).catch(() => '');
assert(errors.length === 0, 'tiada ralat JavaScript' + (errors.length ? ': ' + errors.join(' | ') : ''));
writeFileSync(`${SHOTS}/reference.txt`, ref);
await browser.close();
console.log('SEMUA LULUS', ref, fontFamily);
