<?php
use MyAduan\Home;
use MyAduan\Settings;
use MyAduan\Tests\Integration\TestCase;

final class HomeTest extends TestCase
{
    protected function tearDown(): void
    {
        delete_option(Settings::OPTION);
    }

    public function test_off_by_default(): void
    {
        delete_option(Settings::OPTION);
        $this->assertFalse(Home::enabled());
    }

    public function test_only_bare_front_page_is_served(): void
    {
        update_option(Settings::OPTION, ['home_enabled' => '1']);
        $this->go('/');
        $this->assertTrue(Home::should_serve());
        $this->go('/?s=lampu');
        $this->assertFalse(Home::should_serve());
        update_option(Settings::OPTION, ['home_enabled' => '']);
        $this->go('/');
        $this->assertFalse(Home::should_serve());
    }

    public function test_page_links_and_settings(): void
    {
        update_option(Settings::OPTION, [
            'home_enabled' => '1',
            'form_path' => 'buat-aduan',
            'home_logo_url' => 'https://myaduan.my/logo.png',
            'emergency_text' => 'Hubungi 999 <b>sekarang</b>',
        ]);
        $html = Home::render();
        $this->assertStringContainsString('href="' . home_url('/buat-aduan/') . '"', $html);
        $this->assertStringContainsString('href="' . home_url('/semak/') . '"', $html);
        $this->assertStringContainsString('src="https://myaduan.my/logo.png"', $html);
        $this->assertStringContainsString('home-hero.svg', $html);
        $this->assertStringContainsString('Hubungi 999 &lt;b&gt;sekarang&lt;/b&gt;', $html);
        $this->assertStringContainsString('Sungai Tua', $html);
        $this->assertStringContainsString('Infrastruktur &amp; Kemudahan', $html);
        $this->assertStringContainsString('<meta name="description" content="Laporkan masalah setempat', $html);
        $this->assertStringContainsString('img/home-share.png', $html);
        $this->assertStringContainsString('summary_large_image', $html);
    }

    public function test_custom_description_and_share_image(): void
    {
        update_option(Settings::OPTION, ['home_description' => 'Aduan Gombak "cepat"', 'home_share_image_url' => 'https://myaduan.my/share.jpg']);
        $html = Home::render();
        $this->assertStringContainsString('content="Aduan Gombak &quot;cepat&quot;"', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://myaduan.my/share.jpg">', $html);
        $this->assertStringNotContainsString('og:image:width', $html);
    }

    public function test_initiative_section(): void
    {
        update_option(Settings::OPTION, []);
        $html = Home::render();
        $this->assertStringContainsString('Amirudin Shari', $html);
        $this->assertStringContainsString('img/ahli-parlimen-gombak.jpg', $html);
        update_option(Settings::OPTION, ['home_mp_photo_url' => 'https://myaduan.my/yab.jpg']);
        $this->assertStringContainsString('src="https://myaduan.my/yab.jpg"', Home::render());
        update_option(Settings::OPTION, ['home_mp_name' => '']);
        $this->assertStringNotContainsString('inisiatif-h', Home::render());
    }

    public function test_same_logo_on_every_page(): void
    {
        update_option(Settings::OPTION, []);
        $this->assertStringContainsString('img/logo-myaduan.png', Settings::logo_url());
        update_option(Settings::OPTION, ['home_logo_url' => 'https://myaduan.my/rasmi.png']);
        $this->assertSame('https://myaduan.my/rasmi.png', Settings::logo_url());
        $this->assertStringContainsString('src="https://myaduan.my/rasmi.png"', Home::render());
        $this->assertStringContainsString('data-logo="https://myaduan.my/rasmi.png"', MyAduan\Frontend::form());
        $this->assertStringContainsString('data-logo="https://myaduan.my/rasmi.png"', MyAduan\Frontend::track());
        $admin = (int) wp_insert_user(['user_login' => 'logo' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'logo' . wp_rand() . '@myaduan.my', 'role' => 'administrator']);
        $page = MyAduan\Portal::response(new WP_User($admin))['body'];
        $this->assertStringContainsString('rasmi.png', $page);
        $this->assertStringContainsString('<link rel="icon"', $page);
        $this->assertStringContainsString('<link rel="icon"', MyAduan\Portal::response(new WP_User(0))['body']);
    }

    public function test_diagnose(): void
    {
        update_option(Settings::OPTION, ['home_enabled' => '1']);
        $this->assertStringContainsString('ON', Home::diagnose()[0]);
        update_option(Settings::OPTION, []);
        $this->assertStringContainsString('OFF', Home::diagnose()[0]);
    }

    public function test_saving_settings_purges_litespeed(): void
    {
        $purged = 0;
        $count = static function () use (&$purged): void { $purged++; };
        add_action('litespeed_purge_all', $count);
        update_option(Settings::OPTION, ['home_enabled' => '1']);
        remove_action('litespeed_purge_all', $count);
        $this->assertGreaterThan(0, $purged);
    }

    public function test_sanitize_settings(): void
    {
        $clean = Settings::sanitize(['home_enabled' => 'yes', 'form_path' => '/Borang Aduan/', 'home_image_url' => 'javascript:alert(1)']);
        $this->assertSame('1', $clean['home_enabled']);
        $this->assertSame('borang-aduan', $clean['form_path']);
        $this->assertSame('semak', $clean['track_path']);
        $this->assertSame('', $clean['home_image_url']);
    }

    private function go(string $url): void
    {
        $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = new \WP_Query();
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $vars);
        $GLOBALS['wp_query']->query($vars);
    }
}
