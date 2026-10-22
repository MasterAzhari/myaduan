<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public landing page at the site root (Settings → MyAduan → Halaman utama).
 *
 * A standalone page outside the theme, like the portal: it explains what
 * MyAduan is and sends people to the complaint form. No JavaScript, no
 * personal data, so page caches can keep it.
 */
final class Home
{
    /** Line icons (24×24, stroke) for each category group, by group_order. */
    private const ICONS = [
        1 => '<path d="M4 20 9 4M20 20 15 4M12 6v2M12 11v2M12 16v2"/>',
        2 => '<path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z"/>',
        3 => '<path d="M12 21v-7M12 14c-4 0-7-3-7-7 4 0 7 3 7 7zM12 14c3 0 6-2.5 6-6-3 0-6 2.5-6 6z"/>',
        4 => '<path d="M3 11 12 4l9 7M5 10v10h14V10M10 20v-5h4v5"/>',
        5 => '<path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6l-8-3zM12 8v5M12 16v.5"/>',
        6 => '<path d="M5 17V7a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v10M5 12h14M5 17h14M8 17v3M16 17v3"/><circle cx="8.5" cy="14.5" r=".6"/><circle cx="15.5" cy="14.5" r=".6"/>',
        7 => '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10zM9 11h6M12 8v6"/>',
        8 => '<path d="M2 9l10-5 10 5-10 5L2 9zM6 11v5c3 2 9 2 12 0v-5M22 9v6"/>',
        9 => '<path d="M4 9h16l-1-4H5L4 9zM5 9v11h14V9M9 20v-6h6v6M4 9c0 1.5 1.3 2.5 2.7 2.5S9.3 10.5 9.3 9M9.3 9c0 1.5 1.2 2.5 2.7 2.5s2.7-1 2.7-2.5M14.7 9c0 1.5 1.2 2.5 2.6 2.5S20 10.5 20 9"/>',
        10 => '<path d="M12 3v3M8 21V11a4 4 0 0 1 8 0v10M4 21h16M6 21v-6h2M16 15h2v6"/>',
        11 => '<path d="M4 21h16M5 21V10M19 21V10M9 21V10M15 21V10M3 10h18L12 4 3 10z"/>',
    ];
    private const ICON_OTHER = '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>';

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'maybe_serve'], 0);
    }

    public static function enabled(): bool
    {
        return Settings::get('home_enabled') === '1';
    }

    /** Only the bare front page: searches, feeds, previews and paged blog lists stay with WordPress. */
    public static function should_serve(): bool
    {
        return self::enabled() && is_front_page() && !is_paged() && !is_search() && !is_feed() && !is_preview() && !is_customize_preview();
    }

    public static function maybe_serve(): void
    {
        if (!self::should_serve()) {
            return;
        }
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        echo self::render();
        exit;
    }

    public static function render(): string
    {
        $assets = plugins_url('assets/', MYADUAN_FILE);
        $ver = rawurlencode(MYADUAN_VERSION);
        $form = Settings::page_url('form_path');
        $track = Settings::page_url('track_path');
        $logo = Settings::get('home_logo_url');
        $image = Settings::get('home_image_url');
        $title = 'MyAduan · Sistem Aduan Parlimen Gombak';
        $desc = 'Laporkan masalah setempat di Parlimen Gombak dalam talian. Dapat nombor rujukan serta-merta dan semak status aduan anda sehingga selesai.';
        $icon = get_site_icon_url(64);

        $mark = $logo
            ? '<img class="logo-img" src="' . esc_url($logo) . '" alt="Logo">'
            : self::mark();
        $hero = $image
            ? '<img class="hero-photo" src="' . esc_url($image) . '" alt="" loading="eager">'
            : '<img class="hero-art" src="' . esc_url($assets . 'img/home-hero.svg?ver=' . $ver) . '" alt="" width="560" height="480">';

        $cta = static fn(string $cls, string $text) => '<a class="btn ' . $cls . '" href="' . esc_url($form) . '">' . esc_html($text) . ' <span aria-hidden="true">→</span></a>';

        ob_start();
        ?><!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc_html($title) ?></title>
<meta name="description" content="<?= esc_attr($desc) ?>">
<link rel="canonical" href="<?= esc_url(home_url('/')) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= esc_attr($title) ?>">
<meta property="og:description" content="<?= esc_attr($desc) ?>">
<meta property="og:url" content="<?= esc_url(home_url('/')) ?>">
<?php if ($image) : ?><meta property="og:image" content="<?= esc_url($image) ?>">
<?php endif; ?>
<meta name="theme-color" content="#b3122b">
<?php if ($icon) : ?><link rel="icon" href="<?= esc_url($icon) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= esc_url($assets . 'home.css?ver=' . $ver) ?>">
</head>
<body>
<a class="skip" href="#utama">Langkau ke kandungan</a>
<header class="top">
  <div class="wrap top-in">
    <a class="brand" href="<?= esc_url(home_url('/')) ?>"><?= $mark ?><span><strong>MyAduan</strong><small>Parlimen Gombak</small></span></a>
    <nav aria-label="Menu utama">
      <a class="nav-link" href="#cara">Cara buat aduan</a>
      <a class="nav-link" href="<?= esc_url($track) ?>">Semak status</a>
      <a class="btn btn-sm" href="<?= esc_url($form) ?>">Buat aduan</a>
    </nav>
  </div>
</header>

<main id="utama">
  <section class="hero">
    <div class="wrap hero-in">
      <div class="hero-text">
        <p class="eyebrow">Sistem Aduan Rakyat · Parlimen Gombak</p>
        <h1>Ada masalah di kawasan anda? <span>Adukan terus kepada kami.</span></h1>
        <p class="lead">MyAduan ialah saluran rasmi dalam talian untuk penduduk Parlimen Gombak melaporkan masalah setempat, daripada jalan berlubang dan lampu jalan rosak hinggalah urusan kebajikan. Setiap aduan diberi nombor rujukan dan boleh dijejak sehingga selesai.</p>
        <div class="actions">
          <?= $cta('btn-lg', 'Buat Aduan Sekarang') ?>
          <a class="btn btn-ghost btn-lg" href="<?= esc_url($track) ?>">Semak Status Aduan</a>
        </div>
        <ul class="ticks">
          <li>Percuma</li>
          <li>Tak perlu daftar akaun</li>
          <li>Siap dalam 3 minit dari telefon</li>
        </ul>
      </div>
      <div class="hero-media"><?= $hero ?></div>
    </div>
  </section>

  <section class="band" id="cara" aria-labelledby="cara-h">
    <div class="wrap">
      <h2 id="cara-h">Cara buat aduan</h2>
      <p class="sub">Empat langkah mudah. Anda boleh pantau perkembangan aduan pada bila-bila masa.</p>
      <ol class="steps">
        <li><span class="num">1</span><h3>Isi borang</h3><p>Terangkan masalah, nyatakan lokasi dan lampirkan sehingga 3 gambar jika ada.</p></li>
        <li><span class="num">2</span><h3>Dapat nombor rujukan</h3><p>Nombor rujukan dipaparkan sebaik sahaja aduan dihantar. Simpan nombor ini.</p></li>
        <li><span class="num">3</span><h3>Pegawai ambil tindakan</h3><p>Aduan dikelaskan dan diserahkan kepada pegawai yang bertanggungjawab untuk tindakan susulan.</p></li>
        <li><span class="num">4</span><h3>Semak status</h3><p>Masukkan nombor rujukan atau No. IC di halaman semak status untuk lihat perkembangan terkini.</p></li>
      </ol>
    </div>
  </section>

  <section class="band band-soft" aria-labelledby="jenis-h">
    <div class="wrap">
      <h2 id="jenis-h">Apa yang boleh diadukan?</h2>
      <p class="sub">Pilih sahaja "Buat aduan" dan terangkan masalah anda. Pegawai kami akan kelaskan aduan ke kategori yang betul.</p>
      <ul class="groups">
<?php foreach (self::groups() as $g) : ?>
        <li><span class="ico" aria-hidden="true"><?= self::icon($g['order']) ?></span><div><h3><?= esc_html($g['name']) ?></h3><p><?= $g['items'] ? esc_html(implode(', ', array_slice($g['items'], 0, 4))) . (count($g['items']) > 4 ? ' dan lain-lain' : '') : 'Apa-apa masalah lain di kawasan anda' ?></p></div></li>
<?php endforeach; ?>
      </ul>
    </div>
  </section>

  <section class="band" aria-labelledby="status-h">
    <div class="wrap">
      <h2 id="status-h">Perjalanan aduan anda</h2>
      <p class="sub">Status yang anda akan nampak semasa menyemak aduan.</p>
      <ol class="flow">
        <li><strong>Diterima</strong><span>Aduan sudah masuk sistem.</span></li>
        <li><strong>Dalam semakan</strong><span>Aduan dikelaskan dan diserahkan kepada pegawai.</span></li>
        <li><strong>Dalam tindakan</strong><span>Pegawai sedang mengambil tindakan.</span></li>
        <li><strong>Menunggu pengesahan</strong><span>Tindakan selesai, sedang disahkan.</span></li>
        <li><strong>Ditutup</strong><span>Aduan selesai.</span></li>
      </ol>
    </div>
  </section>

  <section class="band band-soft" aria-labelledby="sedia-h">
    <div class="wrap two">
      <div>
        <h2 id="sedia-h">Sediakan maklumat ini</h2>
        <ul class="checklist">
          <li><strong>Nama penuh dan No. IC</strong> untuk pengesahan dan semakan status.</li>
          <li><strong>Nombor telefon</strong> supaya pegawai boleh menghubungi anda.</li>
          <li><strong>Butiran masalah</strong>: apa berlaku, sejak bila dan kesannya.</li>
          <li><strong>Lokasi</strong>: nama jalan, taman atau tanda tempat. Boleh guna GPS telefon.</li>
          <li><strong>Gambar</strong> (pilihan, sehingga 3 keping) memudahkan tindakan.</li>
        </ul>
      </div>
      <aside class="privacy">
        <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 10V8a6 6 0 0 1 12 0v2M5 10h14v11H5zM12 14v3"/></svg></span>
        <h3>Maklumat peribadi anda selamat</h3>
        <p>Nama, No. IC dan nombor telefon disimpan secara bersulit dan hanya dilihat oleh pegawai yang mengendalikan aduan. Lokasi GPS dalam gambar dibuang secara automatik.</p>
<?php if (Settings::get('privacy_url')) : ?>
        <p><a href="<?= esc_url(Settings::get('privacy_url')) ?>">Baca notis privasi</a></p>
<?php endif; ?>
      </aside>
    </div>
  </section>

  <section class="band" aria-labelledby="kawasan-h">
    <div class="wrap">
      <h2 id="kawasan-h">Kawasan liputan</h2>
      <p class="sub">MyAduan menerima aduan daripada penduduk dalam kawasan Parlimen Gombak.</p>
      <ul class="duns">
<?php foreach (self::duns() as $dun) : ?>
        <li><span class="pin" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 21s-6-5.6-6-11a6 6 0 0 1 12 0c0 5.4-6 11-6 11z"/><circle cx="12" cy="10" r="2.2"/></svg></span><span><small>DUN</small><?= esc_html($dun) ?></span></li>
<?php endforeach; ?>
      </ul>
<?php if (Settings::get('emergency_text')) : ?>
      <p class="emergency"><strong>Kecemasan?</strong> <?= esc_html(Settings::get('emergency_text')) ?></p>
<?php endif; ?>
    </div>
  </section>

  <section class="final">
    <div class="wrap final-in">
      <div>
        <h2>Suara anda membantu kami bertindak.</h2>
        <p>Laporkan masalah hari ini. Kami akan maklumkan perkembangannya melalui nombor rujukan anda.</p>
      </div>
      <?= $cta('btn-light btn-lg', 'Buat Aduan Sekarang') ?>
    </div>
  </section>
</main>

<footer class="foot">
  <div class="wrap foot-in">
    <p><strong>MyAduan</strong> · Sistem Aduan Parlimen Gombak</p>
    <nav aria-label="Pautan bawah">
      <a href="<?= esc_url($form) ?>">Buat aduan</a>
      <a href="<?= esc_url($track) ?>">Semak status</a>
<?php if (Settings::get('privacy_url')) : ?>
      <a href="<?= esc_url(Settings::get('privacy_url')) ?>">Notis privasi</a>
<?php endif; ?>
      <a href="<?= esc_url(Settings::portal_url()) ?>" rel="nofollow">Log masuk pegawai</a>
    </nav>
    <p class="copy">© <?= esc_html(wp_date('Y')) ?> MyAduan Parlimen Gombak</p>
  </div>
</footer>
</body>
</html>
<?php
        return (string) ob_get_clean();
    }

    /** @return list<array{order:int, name:string, items:list<string>}> */
    private static function groups(): array
    {
        $groups = [];
        foreach ((new Catalog())->categories() as $c) {
            $order = (int) $c['group_order'];
            $groups[$order] ??= ['order' => $order, 'name' => $c['group_name'], 'items' => []];
            if ($c['name'] !== $c['group_name']) {
                $groups[$order]['items'][] = $c['name'];
            }
        }
        ksort($groups);
        return array_values($groups);
    }

    /** @return list<string> */
    private static function duns(): array
    {
        return array_column((new Catalog())->areas(), 'name');
    }

    private static function icon(int $order): string
    {
        return '<svg viewBox="0 0 24 24">' . (self::ICONS[$order] ?? self::ICON_OTHER) . '</svg>';
    }

    /** Default MyAduan mark: a speech bubble with a tick. Replace with the official logo via settings. */
    private static function mark(): string
    {
        return '<svg class="logo-mark" viewBox="0 0 40 40" aria-hidden="true"><rect width="40" height="40" rx="10" fill="#b3122b"/>'
            . '<path d="M10 12h20a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3h-9l-6 5v-5h-5a3 3 0 0 1-3-3V15a3 3 0 0 1 3-3z" fill="#fff"/>'
            . '<path d="M14.5 20.5l4 4 7-8" fill="none" stroke="#d4a017" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
}
