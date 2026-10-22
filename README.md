# MyAduan

Sistem aduan Parlimen Gombak atas WordPress. Semua data dalam MySQL, data peribadi disulitkan, tiada Google Sheet atau Apps Script.

Status: **Fasa 3**. Ada database, peranan, aliran status, REST API, borang awam, semak status, gambar private, notifikasi e-mel dan portal admin di `/adminaduan/`. Dashboard penuh datang dalam Fasa 4.

## Pasang

1. Upload `myaduan-x.y.z.zip` di **Plugins → Add New → Upload** dan aktifkan.
2. Semasa aktif, plugin akan:
   - cipta 11 jadual `wp_myaduan_*`
   - isi 50 kategori dan 3 DUN (Sungai Tua, Gombak Setia, Hulu Kelang)
   - cipta peranan Super Admin / Pegawai / Pemerhati MyAduan
   - cipta folder simpanan **di luar** `public_html` (`../myaduan-files/`) dan fail kunci sulitan di dalamnya
3. Kalau ada notis merah "MyAduan" dalam wp-admin, betulkan dulu sebelum guna.
4. Letak shortcode dalam page (Elementor: widget Shortcode):
   - `/aduan/` → `[myaduan_borang]`
   - `/semak/` → `[myaduan_semak]`
5. **Settings → MyAduan**: isi e-mel notifikasi aduan baru dan URL notis privasi.
6. Halaman utama: tandakan **Halaman utama MyAduan** dalam Settings → MyAduan supaya `myaduan.my` terus papar halaman MyAduan.

### Halaman utama (`/`)

- Halaman sendiri di luar tema: apa itu MyAduan, cara buat aduan, jenis aduan (ikut kategori dalam database), perjalanan status, maklumat yang perlu disediakan, kawasan DUN, mesej kecemasan dan butang **Buat Aduan Sekarang**.
- Hanya laman utama yang diganti. Carian, feed dan page lain kekal ikut WordPress/tema. Buang tanda untuk kembali ke halaman utama tema.
- Pautan butang ikut **Laluan borang aduan** (`aduan`) dan **Laluan semak status** (`semak`).
- Logo dan gambar utama boleh ditukar: upload di Media, salin URL ke **URL logo** / **URL gambar utama**. Kosong = logo dan ilustrasi MyAduan sendiri (SVG, dilukis untuk projek ini, bebas guna).
- Tiada JavaScript dan tiada data peribadi, jadi LiteSpeed Cache boleh cache.

### Borang awam

- 5 medan wajib: nama, No. IC, telefon, butiran, lokasi. Gambar (maks 3) dan GPS pilihan.
- Borang berjalan dalam Shadow DOM, jadi CSS tema/Elementor tak rosakkan rupa dia.
- Gambar besar dikecilkan dalam telefon sebelum upload, kemudian server proses semula: maksimum 2000px, EXIF (termasuk lokasi GPS dalam gambar) dibuang, disimpan dalam folder private.
- Draf disimpan dalam tab yang sama sahaja (`sessionStorage`), dibuang lepas berjaya hantar.
- Tekan "Hantar" dua kali atau sambungan putus tak akan jadi aduan berganda.

### E-mel

Dihantar melalui `wp_mail` dari queue `wp_myaduan_mail_queue`, setiap 5 minit, cuba 3 kali. E-mel tak mengandungi nama, IC atau telefon pengadu, cuma rujukan dan link portal.

- Aduan baru → e-mel notifikasi (atau semua Super Admin)
- Kes ditugaskan → pegawai berkenaan
- Mohon penutupan → e-mel notifikasi
- Dikembalikan untuk tindakan → pegawai berkenaan

Pada Hostinger, pasang plugin SMTP (contoh WP Mail SMTP) dengan peti `noreply@myaduan.my` supaya e-mel tak masuk spam.

### Kunci sulitan: WAJIB backup

Nama, IC dan telefon pengadu disulitkan dengan kunci dalam `../myaduan-files/.myaduan-key`. **Kalau kunci hilang, data peribadi tak boleh dibaca lagi.** Simpan salinan kunci di tempat selamat (bukan dalam database).

Cara lebih kemas: pindah kunci ke `wp-config.php`:

```php
define('MYADUAN_ENC_KEY', 'isi-kandungan-fail-.myaduan-key');
```

Pilihan lain dalam `wp-config.php`:

```php
define('MYADUAN_STORAGE_DIR', '/home/uXXXX/myaduan-files'); // kalau nak tukar lokasi folder
```

### Hostinger

- PHP 8.1 atau lebih (cadangan 8.2+), MySQL/MariaDB InnoDB.
- Cron sebenar untuk e-mel: `define('DISABLE_WP_CRON', true);` dalam `wp-config.php`, kemudian hPanel → Advanced → Cron Jobs tiap 5 minit: `php /home/uXXXX/domains/myaduan.my/public_html/wp-cron.php`.
- LiteSpeed Cache boleh cache page `/aduan/` dan `/semak/` (tiada data peribadi dalam HTML), tapi jangan cache `/wp-json/`.

### Portal admin (`/adminaduan/`)

- Halaman sendiri di luar tema (laluan boleh ubah dalam Tetapan → MyAduan). Belum log masuk → dihantar ke login WordPress, kemudian balik ke portal.
- Akaun MyAduan sahaja (Pegawai, Super Admin MyAduan, Pemerhati) terus masuk portal selepas login dan tak boleh masuk wp-admin, kecuali halaman Profil untuk tukar kata laluan.
- **Senarai kes**: kiraan ringkas yang boleh diklik (belum dikelaskan, belum ditugaskan, menunggu pengesahan, belum diterima, lewat susulan), carian dan tapisan. Pautan boleh di-bookmark.
- **Butiran kes**: butiran, gambar, pengadu (telefon + WhatsApp), maklumat kes, sejarah lengkap, dan butang tindakan ikut peranan dan status.
- **Pengguna** (Super Admin): cipta akaun (e-mel tetapan kata laluan dihantar sendiri), tukar peranan dan kategori, nyahaktif. Tak boleh nyahaktif atau buang kategori pegawai yang masih pegang kes terbuka. Akaun Administrator WordPress diurus dalam WordPress.
- Sesuai untuk telefon; pegawai di lapangan boleh terima tugasan dan rekod tindakan dari telefon.

## Peranan

| Peranan | Boleh |
| --- | --- |
| Super Admin MyAduan | Semua kes termasuk yang belum dikelaskan, kelaskan, tugaskan, sahkan penutupan, buka semula |
| Pegawai MyAduan | Lihat semua kes dalam kategori yang dibenarkan + kes sendiri; ubah kes yang ditugaskan sahaja |
| Pemerhati MyAduan | Statistik sahaja, tiada butiran kes |

Kategori pegawai disimpan dalam user meta `myaduan_categories`, contoh `{"mode":"selected","codes":["A01","A02"]}` atau `{"mode":"all"}`. Tiada polisi = tiada akses. Administrator WordPress dapat semua keupayaan Super Admin.

## Aliran status

```
DITERIMA → DALAM SEMAKAN → DALAM TINDAKAN → MENUNGGU PENGESAHAN → DITUTUP
                                 ↑ return ←────────┘                 │
                                 ↑ reopen ←──────────────────────────┘
```

Operasi: `classify`, `assign`, `accept`, `action`, `comment`, `request_close`, `approve_close`, `return`, `reopen`, `publish`. Peraturan penuh dalam `includes/Workflow.php`.

## REST API (`/wp-json/myaduan/v1`)

| Kaedah | Laluan | Siapa |
| --- | --- | --- |
| POST | `/complaints` | Awam, multipart. `client_key` (64 hex), `name`, `ic`, `phone`, `details`, `location`, `consent`, pilihan `lat`/`lng`, `images[]` (maks 3, JPG/PNG/WebP, 5 MB). Had 5 sejam setiap IP |
| POST | `/track` | Awam. `query` = rujukan atau IC. Pulang rujukan, status, mesej awam, tarikh sahaja |
| GET | `/admin/me` | Pengguna MyAduan |
| GET | `/admin/catalog` | Pengguna MyAduan (senarai pegawai untuk Super Admin) |
| GET | `/admin/stats` | Pengguna MyAduan |
| GET | `/admin/cases` | Super Admin, Pegawai. Filter: `status`, `flag` (`open`, `not_accepted`, `overdue`), `owner` (id atau `unassigned`), `category` (`none` = belum dikelaskan), `dun`, `area`, `q`, `ic`, `from`, `to`, `page` |
| GET | `/admin/cases/{id}` | Super Admin, Pegawai (dicatat dalam `access_log`) |
| GET | `/admin/files/{id}` | Super Admin, Pegawai yang ada akses kes (dicatat dalam `access_log`) |
| POST | `/admin/cases/{id}/operations` | `op`, `version`, `request_id` (64 hex) + medan operasi |
| GET, POST | `/admin/users` | Super Admin. POST: `name`, `email`, `role` (`superadmin`/`officer`/`viewer`), `policy` untuk pegawai |
| POST | `/admin/users/{id}` | Super Admin. Mana-mana medan di atas + `active` |

Panggilan admin guna cookie login + header `X-WP-Nonce` (`wp_create_nonce('wp_rest')`).

## Pembangunan

```bash
phpunit                                   # ujian unit
WP_ROOT=/path/ke/wordpress-ujian phpunit -c phpunit-integration.xml.dist   # ujian WordPress + MySQL sebenar
CHROMIUM_PATH=... BASE_URL=http://localhost:8080 node tests/e2e/borang.mjs   # uji borang dalam browser (perlu npm i playwright)
CHROMIUM_PATH=... BASE_URL=http://localhost:8080 node tests/e2e/portal.mjs   # uji portal: akaun e2e_super & e2e_officer (kategori A04), kata laluan E2E_PASS
bash bin/build-zip.sh                     # hasilkan dist/myaduan-x.y.z.zip
```

Ujian integrasi akan TRUNCATE jadual `wp_myaduan_*`. Jangan sekali-kali halakan ke site live.
