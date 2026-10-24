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
- Logo lalai: `assets/img/logo-myaduan.png` (logo rasmi MyAduan Parlimen Gombak). Logo yang sama dipakai di halaman utama, borang aduan, semak status, portal admin dan halaman log masuk; ia juga jadi favicon kalau Site Icon WordPress kosong. Logo dan gambar utama boleh ditukar: upload di Media, salin URL ke **URL logo** / **URL gambar utama**. Ilustrasi utama (SVG) dilukis untuk projek ini.
- Bahagian **Inisiatif** Ahli Parlimen (nama, jawatan, gambar) boleh ubah dalam Settings → MyAduan. Nama kosong = bahagian disembunyikan. Gambar lalai: `assets/img/ahli-parlimen-gombak.jpg` (dibekalkan oleh Azhari).
- **Meta description** dan **URL gambar preview** (feature image, 1200×630) untuk Google dan preview link di WhatsApp/Facebook. Gambar preview lalai: `assets/img/home-share.png`.
- Tiada JavaScript dan tiada data peribadi, jadi LiteSpeed Cache boleh cache.

### Borang awam

- Gaya langkah demi langkah (ikut borang asal MyAduan): soalan kecemasan → butiran → lokasi (+GPS) → gambar → nama → IC & telefon → semakan dengan butang Ubah. Panel tepi tunjuk peringkat, ada bar kemajuan.
- 5 medan wajib: nama, No. IC, telefon, butiran, lokasi. Gambar (maks 3) dan GPS pilihan.
- Borang dan semak status guna bingkai yang sama, dengan suis Buat Aduan / Semak Status Aduan. Bingkai melebar keluar dari kolum tema yang sempit (maks 1120px).
- Borang berjalan dalam Shadow DOM, jadi CSS tema/Elementor tak rosakkan rupa dia.
- Gambar besar dikecilkan dalam telefon sebelum upload, kemudian server proses semula: maksimum 2000px, EXIF (termasuk lokasi GPS dalam gambar) dibuang, disimpan dalam folder private.
- Draf (termasuk langkah semasa) disimpan dalam tab yang sama sahaja (`sessionStorage`), dibuang lepas berjaya hantar.
- Tekan "Hantar" dua kali atau sambungan putus tak akan jadi aduan berganda.

### E-mel

Dihantar melalui `wp_mail` dari queue `wp_myaduan_mail_queue`, setiap 5 minit, cuba 3 kali. Semua e-mel MyAduan dihantar **dari `info@myaduan.my`** (Tetapan → MyAduan → E-mel penghantar, mesti @myaduan.my). E-mel kepada staf tak mengandungi nama, IC atau telefon pengadu, cuma rujukan dan link portal.

- Aduan baru → e-mel notifikasi (atau semua Super Admin)
- Aduan baru → **pengadu**, jika dia isi e-mel (pilihan) dalam borang: nombor rujukan + link semak status. E-mel pengadu disimpan bersulit; alamat dalam queue dipadam lepas dihantar.
- Kes ditugaskan → pegawai berkenaan
- Mohon penutupan → e-mel notifikasi
- Dikembalikan untuk tindakan → pegawai berkenaan

Pada Hostinger, cipta mailbox `info@myaduan.my` dan pasang plugin SMTP (contoh WP Mail SMTP) guna mailbox itu supaya e-mel tak masuk spam.

### Import dari Google Sheet lama

Tetapan → MyAduan → **Import aduan dari Google Sheet lama**. Dalam Google Sheet, buka tab `ADUAN` → File → Download → CSV, kemudian upload. Atau melalui SSH: `wp myaduan import-sheet aduan.csv`.

- Nombor rujukan asal dikekalkan; rujukan yang sudah ada dilangkau, jadi selamat diulang.
- Nama, IC, telefon dan e-mel disulitkan seperti aduan baru. Alamat rumah dan poskod pengadu tidak disimpan.
- Status lama: BARU → DITERIMA, MENUNGGU MAKLUM BALAS → DALAM TINDAKAN, SELESAI → MENUNGGU PENGESAHAN. Pegawai, catatan dan kategori asal disimpan dalam sejarah kes (event `IMPORT`).
- Gambar dari `Gambar_Bukti_URL` dimuat turun dari Google Drive (maks 3 setiap kes) jika fail dikongsi "Anyone with the link". Yang gagal kekal sebagai link dalam sejarah; import semula akan cuba lagi untuk kes yang belum ada gambar.

### Akaun staf: wajib @myaduan.my

Akaun admin, Super Admin dan pegawai yang dicipta atau diubah dalam portal mesti guna e-mel `@myaduan.my`. E-mel notifikasi dan e-mel penghantar pun mesti @myaduan.my; alamat lain dibuang semasa simpan. Akaun lama dengan domain lain ditanda merah dalam senarai Pengguna dan perlu ditukar sebelum perubahan lain boleh disimpan.

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
