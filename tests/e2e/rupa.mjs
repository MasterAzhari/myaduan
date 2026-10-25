// Screenshots of every portal page on a phone and on a desktop, to compare the look before and after a change.
// Usage: BASE_URL=http://localhost:8080 SHOTS=/tmp/rupa node tests/e2e/rupa.mjs
// Uses the e2e_super and e2e_officer accounts (password in E2E_PASS) and needs at least one case.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.BASE_URL || 'http://localhost:8080';
const SHOTS = process.env.SHOTS || '/tmp/myaduan-rupa';
const PASS = process.env.E2E_PASS || 'Ujian-12345';
mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
const errors = [];

async function session(user, viewport, scale) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: scale });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(BASE + '/adminaduan/');
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(PASS);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/\/adminaduan\//);
  await page.locator('main#view h1').first().waitFor();
  return page;
}

async function shoot(page, hash, name) {
  await page.goto(BASE + '/adminaduan/' + hash);
  await page.locator('main#view h1').first().waitFor();
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
}

const PAGES = [['#/kes', 'senarai'], ['#/agensi', 'agensi'], ['#/kawasan', 'kawasan'], ['#/emel', 'emel'], ['#/pengguna', 'pengguna'], ['#/notifikasi', 'notifikasi'], ['#/profil', 'profil']];
for (const [label, viewport, scale] of [['telefon', { width: 390, height: 844 }, 2], ['desktop', { width: 1366, height: 900 }, 1]]) {
  const page = await session('e2e_super', viewport, scale);
  for (const [hash, name] of PAGES) await shoot(page, hash, `${label}-${name}`);
  await page.goto(BASE + '/adminaduan/#/kes');
  await page.locator('a.ref').first().click();
  await page.locator('.case-head').waitFor();
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${SHOTS}/${label}-kes.png`, fullPage: true });
  if (label === 'telefon') {
    await page.screenshot({ path: `${SHOTS}/${label}-kes-atas.png` });
    await page.goto(BASE + '/adminaduan/#/kes');
    await page.locator('main#view h1').first().waitFor();
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${SHOTS}/${label}-senarai-atas.png` });
  }
  await page.context().close();
}
const officer = await session('e2e_officer', { width: 390, height: 844 }, 2);
await shoot(officer, '#/kes', 'telefon-pegawai-senarai');
await browser.close();
if (errors.length) { console.error('Ralat JavaScript:', errors); process.exit(1); }
console.log('Siap:', SHOTS);
