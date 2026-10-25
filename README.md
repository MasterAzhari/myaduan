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

Dihantar melalui `wp_mail` dari queue `wp_myaduan_mail_queue`, setiap 5 minit, cuba 3 kali. Semua e-mel MyAduan dihantar **dari `info@myaduan.my`** (Tetapan → MyAduan → E-mel penghantar, mesti @myaduan.my).

- Aduan baru → **pengadu**, jika dia isi e-mel (pilihan) dalam borang: nombor rujukan + link semak status. E-mel pengadu disimpan bersulit; alamat dalam queue dipadam lepas dihantar.
- Kes dipanjangkan → **agensi** (e-mel utama + CC): butiran aduan, lokasi, peta dan gambar (dilampirkan). **Tiada maklumat pengadu** (nama, IC, telefon, e-mel). Balasan agensi masuk ke `info@myaduan.my`.
- Notifikasi staf kini dalam portal (lihat di bawah). Salinan e-mel kepada staf hanya jika "E-mel notifikasi staf" ditanda dalam Tetapan (asal: tutup). E-mel staf tak mengandungi maklumat pengadu.

Pada Hostinger, cipta mailbox `info@myaduan.my` dan pasang plugin SMTP (contoh WP Mail SMTP) guna mailbox itu supaya e-mel tak masuk spam.

### Import dari Google Sheet lama

Tetapan → MyAduan → **Import aduan dari Google Sheet lama**. Dalam Google Sheet, buka tab `ADUAN` → File → Download → CSV, kemudian upload. Atau melalui SSH: `wp myaduan import-sheet aduan.csv`.

- Nombor rujukan asal dikekalkan; rujukan yang sudah ada dilangkau, jadi selamat diulang.
- Nama, IC, telefon dan e-mel disulitkan seperti aduan baru. Alamat rumah dan poskod pengadu tidak disimpan.
- Status lama: BARU → DITERIMA, MENUNGGU MAKLUM BALAS → DALAM TINDAKAN, SELESAI → MENUNGGU PENGESAHAN. Pegawai, catatan dan kategori asal disimpan dalam sejarah kes (event `IMPORT`).
- Gambar dari `Gambar_Bukti_URL` dimuat turun dari Google Drive (maks 3 setiap kes) jika fail dikongsi "Anyone with the link". Yang gagal kekal sebagai link dalam sejarah; import semula akan cuba lagi untuk kes yang belum ada gambar.

### Akaun staf: wajib @myaduan.my

Akaun admin, Super Admin dan pegawai yang dicipta atau diubah dalam portal mesti guna e-mel `@myaduan.my`. E-mel notifikasi dan e-mel penghantar pun mesti @myaduan.my; alamat lain dibuang semasa simpan. Akaun lama dengan domain lain ditanda merah dalam senarai Pengguna dan perlu ditukar sebelum perubahan lain boleh disimpan.

Akaun @myaduan.my yang dibuat dalam wp-admin tanpa peranan MyAduan pun keluar dalam senarai Pengguna (paling atas, "Perlu peranan"). Super Admin tekan **Beri peranan** untuk set peranan dan kategori terus dari portal.

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
- E-mel dihantar serta-merta selepas tindakan (selepas jawapan dihantar ke pelayar). Cron hanya menghantar yang tertinggal dan menyemak mailbox setiap 5 minit.
- Cron sebenar: `define('DISABLE_WP_CRON', true);` dalam `wp-config.php`, kemudian hPanel → Advanced → Cron Jobs tiap 5 minit: `php /home/uXXXX/domains/myaduan.my/public_html/wp-cron.php`.
- LiteSpeed Cache boleh cache page `/aduan/` dan `/semak/` (tiada data peribadi dalam HTML), tapi jangan cache `/wp-json/`.

### Portal admin (`/adminaduan/`)

- Halaman sendiri di luar tema (laluan boleh ubah dalam Tetapan → MyAduan). Belum log masuk → dihantar ke login WordPress, kemudian balik ke portal.
- Akaun MyAduan sahaja (Pegawai, Super Admin MyAduan, Pemerhati) terus masuk portal selepas login dan tak boleh masuk wp-admin (profile.php dihantar ke halaman Profil portal).
- **Senarai kes**: kiraan ringkas yang boleh diklik (belum dikelaskan, belum ditugaskan, menunggu pengesahan, belum diterima, lewat susulan), carian dan tapisan. Pautan boleh di-bookmark.
- **Butiran kes**: butiran, gambar, pengadu (telefon + WhatsApp), maklumat kes, sejarah lengkap, dan butang tindakan ikut peranan dan status.
- **Pengguna** (Super Admin): cipta akaun dengan nama, e-mel @myaduan.my dan kata laluan (sekurang-kurangnya 8 aksara, huruf dan nombor), tukar peranan dan kategori, nyahaktif. Jika staf terlupa kata laluan, Super Admin tekan **Ubah** dan isi **Kata laluan baru**; staf itu dilog keluar dari semua peranti. Tak boleh nyahaktif atau buang kategori pegawai yang masih pegang kes terbuka. Akaun Administrator WordPress diurus dalam WordPress.
- **Profil** (semua): lihat nama, e-mel dan peranan, dan tukar kata laluan sendiri (perlu kata laluan semasa). Peranti lain dilog keluar. Reset kata laluan melalui e-mel ditutup untuk akaun MyAduan; minta Super Admin. Pentadbir laman WordPress masih boleh guna pautan "Lost your password?".
- Sesuai untuk telefon; pegawai di lapangan boleh terima tugasan dan rekod tindakan dari telefon.
- **Keputusan kes** (hanya pegawai yang ditugaskan): *Panjangkan ke agensi* (pilih agensi, e-mel dihantar), *Tindakan sendiri*, *Perlu maklumat lanjut*, atau *Tutup kes* (mohon penutupan → Super Admin sahkan). Tiada siapa boleh proses aduan sebelum Super Admin tugaskan kepada pegawai atau dirinya sendiri. *Status agensi* rekod maklum balas dan no. rujukan agensi.
- **Agensi** (Super Admin): 40 agensi dari senarai rasmi dimasukkan semasa pasang. Tambah, ubah e-mel/CC, atau nyahaktif.
- **Kawasan** (Super Admin): senarai taman/kampung bagi setiap DUN. Tampal banyak nama sekaligus (satu nama satu baris), ubah nama, atau sembunyikan. Nama yang disembunyikan kekal pada kes lama. Taman yang belum ada juga boleh ditambah terus dari borang "Kelaskan / tugaskan".
- **E-mel rujukan agensi** dihantar dari info@myaduan.my dengan nama pegawai sebagai penghantar ("Nama (MyAduan)") dan Reply-To info@. Status e-mel dipapar di sebelah setiap agensi dalam kes: *Belum dihantar*, *Dihantar* (pelayan e-mel kita telah menghantarnya keluar), *Gagal* (dengan ralat) atau *Bounce* (dengan sebab).
- **E-mel** (Super Admin): log semua e-mel dengan status, tapisan, *Hantar semula* untuk yang gagal/bounce, *Hantar & semak sekarang*, e-mel ujian, dan sambungan mailbox IMAP (info@, imap.hostinger.com:993). Kata laluan mailbox disulitkan dan tidak dipaparkan semula.
- **Mailbox info@** disemak setiap 5 minit (baca sahaja): laporan bounce yang memetik pengepala `X-MyAduan-Id` menanda e-mel itu *Bounce*; balasan agensi yang menyebut rujukan kes dan datang dari agensi yang dirujuk (alamat, CC atau domain sama) masuk ke sejarah kes sebagai *Balasan agensi* dan menjadi notifikasi kepada pegawai yang memegang kes. Petikan e-mel asal dibuang.
- **Notifikasi** (loceng): aduan baru, tugasan, mohon penutupan, dikembalikan, dibuka semula, ditutup, dipanjangkan, catatan. Buka kes = notifikasi kes itu dibaca.
- **Pasang di telefon (PWA)**: portal boleh "Add to Home Screen" / "Install app". Di halaman Notifikasi tekan **Aktifkan notifikasi** untuk notifikasi push di peranti itu. iPhone perlu iOS 16.4+ dan mesti dibuka dari ikon skrin utama dulu. Push dihantar terus dari server (VAPID, tiada perkhidmatan luar), kunci dibuat sendiri semasa pertama kali digunakan.

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
| POST | `/admin/me/password` | Pengguna MyAduan. `current`, `password` |
| GET | `/admin/catalog` | Pengguna MyAduan (senarai pegawai untuk Super Admin) |
| GET | `/admin/stats` | Pengguna MyAduan |
| GET | `/admin/cases` | Super Admin, Pegawai. Filter: `status`, `flag` (`open`, `not_accepted`, `overdue`, `agency`, `need_info`), `owner` (id atau `unassigned`), `category` (`none` = belum dikelaskan), `dun`, `area`, `q`, `ic`, `from`, `to`, `page` |
| GET | `/admin/cases/{id}` | Super Admin, Pegawai (dicatat dalam `access_log`) |
| GET | `/admin/files/{id}` | Super Admin, Pegawai yang ada akses kes (dicatat dalam `access_log`) |
| POST | `/admin/cases/{id}/operations` | `op`, `version`, `request_id` (64 hex) + medan operasi |
| GET, POST | `/admin/users` | Super Admin. POST: `name`, `email`, `password`, `role` (`superadmin`/`officer`/`viewer`), `policy` untuk pegawai |
| POST | `/admin/users/{id}` | Super Admin. Mana-mana medan di atas + `active`; `password` kosong = tiada perubahan |
| GET, POST | `/admin/agencies`, POST `/admin/agencies/{id}` | Super Admin. `name`, `officer`, `email`, `cc`, `active` |
| GET, POST | `/admin/areas`, POST `/admin/areas/{id}` | Super Admin. Tambah: `dun_slug`, `names` (satu baris satu nama). Ubah: `name`, `active` |
| GET | `/admin/mail` | Super Admin. `state` (`pending`/`sent`/`failed`/`bounced`). Pulang log, kiraan dan status mailbox |
| POST | `/admin/mail/{id}/retry`, `/admin/mail/run`, `/admin/mail/test` | Super Admin. `test`: `to` |
| POST, DELETE | `/admin/mailbox` | Super Admin. `host`, `port`, `user`, `pass` (kosong = kekal); disimpan selepas log masuk berjaya |
| GET | `/admin/notifications` | Pengguna MyAduan. `unread=1`, `count=1` |
| POST | `/admin/notifications/read` | `ids` (kosong = semua) |
| GET, POST, DELETE | `/admin/push` | Kunci awam VAPID; simpan/buang langganan push peranti |

Operasi keputusan: `refer` (`agency_ids`, `note` = mesej kepada agensi), `self`, `need_info` (`note`), `agency_update` (`referral_id`, `agency_status`, `reference_no`); semua boleh tambah `next_action`, `followup_at`.

Panggilan admin guna cookie login + header `X-WP-Nonce` (`wp_create_nonce('wp_rest')`).

## Pembangunan

```bash
phpunit                                   # ujian unit
WP_ROOT=/path/ke/wordpress-ujian phpunit -c phpunit-integration.xml.dist   # ujian WordPress + MySQL sebenar
CHROMIUM_PATH=... BASE_URL=http://localhost:8080 node tests/e2e/borang.mjs   # uji borang dalam browser (perlu npm i playwright)
CHROMIUM_PATH=... BASE_URL=http://localhost:8080 node tests/e2e/portal.mjs   # uji portal: akaun e2e_super & e2e_officer (kategori A04), kata laluan E2E_PASS
CHROMIUM_PATH=... BASE_URL=http://localhost:8080 SHOTS=/tmp/rupa node tests/e2e/rupa.mjs   # screenshot semua halaman portal (telefon & desktop) untuk banding rupa
bash bin/build-zip.sh                     # hasilkan dist/myaduan-x.y.z.zip
```

Ujian integrasi akan TRUNCATE jadual `wp_myaduan_*`. Jangan sekali-kali halakan ke site live.
