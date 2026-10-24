<?php
use MyAduan\Import;
use MyAduan\Schema;
use MyAduan\Tests\Integration\TestCase;

final class ImportTest extends TestCase
{
    private string $csv;
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->csv = tempnam(sys_get_temp_dir(), 'sheet');
        add_filter('pre_http_request', [$this, 'drive'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'drive'], 10);
        @unlink($this->csv);
    }

    /** Fake Google Drive: "PUBLICFILE" ids return a photo, anything else the login page. */
    public function drive($pre, array $args, string $url)
    {
        if (!str_starts_with($url, 'https://drive.google.com/')) {
            return $pre;
        }
        $this->fetched[] = $url;
        if (str_contains($url, 'PUBLICFILE')) {
            $img = imagecreatetruecolor(2400, 1200);
            ob_start();
            imagejpeg($img);
            return ['headers' => [], 'body' => ob_get_clean(), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }
        return ['headers' => [], 'body' => '<html>Sign in</html>', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
    }

    private function write(array $rows): void
    {
        $fh = fopen($this->csv, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        $head = ['No_Rujukan', 'Tarikh_Masa', 'Nama_Penuh', 'No_KP', 'No_Telefon', 'Email', 'Alamat_Pengadu', 'Poskod', 'DUN', 'Lokasi_Kejadian', 'Kod_Kategori', 'Kategori_Aduan', 'Tahap_Keutamaan', 'Tajuk_Aduan', 'Butiran_Aduan', 'Tarikh_Kejadian', 'Gambar_Bukti_URL', 'Koordinat', 'Persetujuan_PDPA', 'Status', 'Pegawai', 'Catatan', 'Tarikh_Kemas_Kini'];
        fputcsv($fh, $head, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn($k) => $r[$k] ?? '', $head), ',', '"', '');
        }
        fclose($fh);
    }

    public function test_imports_sheet_rows_with_photos(): void
    {
        global $wpdb;
        $this->write([
            [
                'No_Rujukan' => 'GOM-20260901-ABCDEF0123', 'Tarikh_Masa' => '2026-09-01 14:30:00', 'Nama_Penuh' => 'Ali bin Abu', 'No_KP' => '900101-14-5678',
                'No_Telefon' => '012-345 6789', 'Email' => 'Ali@Contoh.com', 'Alamat_Pengadu' => 'No 1 Jalan Rumah', 'Poskod' => '53100', 'DUN' => 'Hulu Kelang',
                'Lokasi_Kejadian' => 'Jalan Kolam Air', 'Kod_Kategori' => 'A04', 'Kategori_Aduan' => 'Lampu Jalan', 'Tahap_Keutamaan' => 'SEGERA',
                'Tajuk_Aduan' => 'Lampu jalan rosak', 'Butiran_Aduan' => "Lampu jalan padam.\nSudah seminggu.", 'Koordinat' => '3.2101, 101.7302',
                'Gambar_Bukti_URL' => 'https://drive.google.com/file/d/PUBLICFILE123/view https://drive.google.com/file/d/PRIVATEFILE99/view',
                'Status' => 'MENUNGGU MAKLUM BALAS', 'Pegawai' => 'En. Kamal', 'Catatan' => 'Sudah maklum MPS', 'Tarikh_Kemas_Kini' => '03/09/2026 09:00:00',
            ],
            ['No_Rujukan' => 'GOM-20260902-0000000001', 'Tarikh_Masa' => '2026-09-02 08:00:00', 'Nama_Penuh' => 'Siti', 'No_KP' => '880512105432', 'No_Telefon' => '0129876543', 'Butiran_Aduan' => 'Longkang tersumbat depan rumah.', 'Status' => 'BARU', 'DUN' => 'Tiada DUN', 'Kod_Kategori' => 'Z99'],
            ['No_Rujukan' => 'BUKAN-RUJUKAN', 'Butiran_Aduan' => 'x'],
            ['No_Rujukan' => 'GOM-20260903-0000000002', 'Butiran_Aduan' => ''],
        ]);
        $r = Import::csv($this->csv, self::$users['super']);
        $this->assertSame(2, $r['imported'], wp_json_encode($r));
        $this->assertSame(1, $r['photos']);
        $this->assertSame(1, $r['photos_failed']);
        $this->assertCount(2, $r['errors']);

        $id = $this->case_id('GOM-20260901-ABCDEF0123');
        [$status, $c] = $this->call('GET', "/admin/cases/$id", [], 'super');
        $this->assertSame(200, $status);
        $this->assertSame(['Ali bin Abu', '900101145678', '60123456789', 'ali@contoh.com'], array_values($c['complainant']));
        $this->assertSame(['DALAM TINDAKAN', 'SEGERA', 'A04', 'hulu-kelang', 'Lampu jalan rosak'], [$c['status'], $c['priority'], $c['category_code'], $c['dun_slug'], $c['title']]);
        $this->assertSame('2026-09-01T06:30:00Z', $c['created_at'], 'Malaysia time stored as UTC');
        $this->assertSame([3.2101, 101.7302], [$c['lat'], $c['lng']]);
        $this->assertCount(1, $c['attachments']);
        $note = $c['events'][0]['note'];
        $this->assertSame('IMPORT', $c['events'][0]['event']);
        $this->assertStringContainsString('Pegawai asal: En. Kamal', $note);
        $this->assertStringContainsString('Catatan: Sudah maklum MPS', $note);
        $this->assertStringContainsString('PRIVATEFILE99', $note, 'photo links kept for officers');
        $this->assertStringNotContainsString('Jalan Rumah', $note, 'home address not kept');
        $this->assertStringNotContainsString('Jalan Rumah', (string) $wpdb->get_var('SELECT GROUP_CONCAT(details, location_text) FROM ' . Schema::table('cases')));

        $other = $this->call('GET', '/admin/cases/' . $this->case_id('GOM-20260902-0000000001'), [], 'super')[1];
        $this->assertSame(['DITERIMA', null, null], [$other['status'], $other['category_code'], $other['dun_slug']]);
        [, $track] = $this->call('POST', '/track', ['query' => '880512-10-5432']);
        $this->assertSame('GOM-20260902-0000000001', $track['items'][0]['reference'], 'imported cases can be tracked by IC');

        // Running again adds nothing new and only retries photos of cases that have none.
        $this->fetched = [];
        $again = Import::csv($this->csv, self::$users['super']);
        $this->assertSame([0, 2], [$again['imported'], $again['skipped']]);
        $this->assertSame([], $this->fetched, 'case with a photo already is not fetched again');
        $this->assertSame('2', $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('cases')));
    }

    public function test_rejects_wrong_file(): void
    {
        file_put_contents($this->csv, "Nama,Umur\nAli,30\n");
        $r = Import::csv($this->csv, 0);
        $this->assertSame(0, $r['imported']);
        $this->assertStringContainsString('ADUAN', $r['errors'][0]);
    }

    public function test_drive_link_formats(): void
    {
        $this->assertSame(['abcDEF12345', 'xyz_-987654321'], Import::drive_ids('https://drive.google.com/file/d/abcDEF12345/view?usp=drivesdk, https://drive.google.com/open?id=xyz_-987654321'));
    }
}
