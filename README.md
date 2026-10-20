# MyAduan

Sistem aduan Parlimen Gombak atas WordPress. Semua data dalam MySQL, data peribadi disulitkan, tiada Google Sheet atau Apps Script.

Status: **Fasa 2**. Ada database, peranan, aliran status, REST API, borang awam, semak status, gambar private dan notifikasi e-mel. Portal admin dan dashboard datang dalam Fasa 3 dan 4.

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
| GET | `/admin/cases` | Super Admin, Pegawai. Filter: `status`, `owner` (id atau `unassigned`), `category` (`none` = belum dikelaskan), `dun`, `area`, `q`, `ic`, `from`, `to`, `page` |
| GET | `/admin/cases/{id}` | Super Admin, Pegawai (dicatat dalam `access_log`) |
| GET | `/admin/files/{id}` | Super Admin, Pegawai yang ada akses kes (dicatat dalam `access_log`) |
| POST | `/admin/cases/{id}/operations` | `op`, `version`, `request_id` (64 hex) + medan operasi |

Panggilan admin guna cookie login + header `X-WP-Nonce` (`wp_create_nonce('wp_rest')`).

## Pembangunan

```bash
phpunit                                   # ujian unit
WP_ROOT=/path/ke/wordpress-ujian phpunit -c phpunit-integration.xml.dist   # ujian WordPress + MySQL sebenar
CHROMIUM_PATH=... BASE_URL=http://localhost:8080 node tests/e2e/borang.mjs   # uji borang dalam browser (perlu npm i playwright)
bash bin/build-zip.sh                     # hasilkan dist/myaduan-x.y.z.zip
```

Ujian integrasi akan TRUNCATE jadual `wp_myaduan_*`. Jangan sekali-kali halakan ke site live.
