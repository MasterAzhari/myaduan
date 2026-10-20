# MyAduan

Sistem aduan Parlimen Gombak atas WordPress. Semua data dalam MySQL, data peribadi disulitkan, tiada Google Sheet atau Apps Script.

Status: **Fasa 1 (asas)**. Ada database, peranan, aliran status dan REST API. Borang awam, portal admin dan dashboard datang dalam Fasa 2 hingga 4.

## Pasang

1. Upload `myaduan-x.y.z.zip` di **Plugins → Add New → Upload** dan aktifkan.
2. Semasa aktif, plugin akan:
   - cipta 11 jadual `wp_myaduan_*`
   - isi 50 kategori dan 3 DUN (Sungai Tua, Gombak Setia, Hulu Kelang)
   - cipta peranan Super Admin / Pegawai / Pemerhati MyAduan
   - cipta folder simpanan **di luar** `public_html` (`../myaduan-files/`) dan fail kunci sulitan di dalamnya
3. Kalau ada notis merah "MyAduan" dalam wp-admin, betulkan dulu sebelum guna.

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
- LiteSpeed Cache: kecualikan `/wp-json/myaduan/` dari cache.
- Cron sebenar (untuk email, Fasa 2): `define('DISABLE_WP_CRON', true);` dan Cron Job hPanel tiap 5 minit ke `wp-cron.php`.

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
| POST | `/complaints` | Awam. `client_key` (64 hex), `name`, `ic`, `phone`, `details`, `location`, `consent`, pilihan `lat`/`lng`. Had 5 sejam setiap IP |
| POST | `/track` | Awam. `query` = rujukan atau IC. Pulang rujukan, status, mesej awam, tarikh sahaja |
| GET | `/admin/me` | Pengguna MyAduan |
| GET | `/admin/catalog` | Pengguna MyAduan (senarai pegawai untuk Super Admin) |
| GET | `/admin/stats` | Pengguna MyAduan |
| GET | `/admin/cases` | Super Admin, Pegawai. Filter: `status`, `owner` (id atau `unassigned`), `category` (`none` = belum dikelaskan), `dun`, `area`, `q`, `ic`, `from`, `to`, `page` |
| GET | `/admin/cases/{id}` | Super Admin, Pegawai (dicatat dalam `access_log`) |
| POST | `/admin/cases/{id}/operations` | `op`, `version`, `request_id` (64 hex) + medan operasi |

Panggilan admin guna cookie login + header `X-WP-Nonce` (`wp_create_nonce('wp_rest')`).

## Pembangunan

```bash
phpunit                                   # ujian unit
WP_ROOT=/path/ke/wordpress-ujian phpunit -c phpunit-integration.xml.dist   # ujian WordPress + MySQL sebenar
bash bin/build-zip.sh                     # hasilkan dist/myaduan-x.y.z.zip
```

Ujian integrasi akan TRUNCATE jadual `wp_myaduan_*`. Jangan sekali-kali halakan ke site live.
