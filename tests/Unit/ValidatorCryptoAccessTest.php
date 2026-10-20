<?php
use MyAduan\Access;
use MyAduan\Crypto;
use MyAduan\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorCryptoAccessTest extends TestCase
{
    public function test_ic_normalization(): void
    {
        $this->assertSame('900101145678', Validator::normalize_ic('900101-14-5678'));
        $this->assertSame('900101145678', Validator::normalize_ic(' 900101 14 5678 '));
        $this->assertNull(Validator::normalize_ic('901301145678'), 'month 13');
        $this->assertNull(Validator::normalize_ic('900100145678'), 'day 00');
        $this->assertNull(Validator::normalize_ic('90010114567'), '11 digits');
        $this->assertNull(Validator::normalize_ic('90010114567X'));
    }

    public function test_phone_normalization(): void
    {
        $this->assertSame('60123456789', Validator::normalize_phone('012-345 6789'));
        $this->assertSame('601123456789', Validator::normalize_phone('+60 11-2345 6789'));
        $this->assertSame('60361234567', Validator::normalize_phone('03-6123 4567'));
        $this->assertNull(Validator::normalize_phone('12345'));
        $this->assertNull(Validator::normalize_phone('012-345 6789 ext 2'));
    }

    public function test_complaint_valid_and_gps_dropped_outside_malaysia(): void
    {
        $r = Validator::complaint([
            'name' => '  Ali  bin Abu ', 'ic' => '900101-14-5678', 'phone' => '0123456789',
            'details' => "Lubang besar di jalan utama.\nBahaya kepada motosikal.", 'location' => 'Jalan 2, Taman Sri Gombak',
            'consent' => true, 'lat' => '51.5', 'lng' => '-0.1',
        ]);
        $this->assertSame([], $r['errors']);
        $this->assertSame('Ali bin Abu', $r['data']['name']);
        $this->assertStringContainsString("\n", $r['data']['details'], 'line breaks kept');
        $this->assertNull($r['data']['lat']);
    }

    public function test_complaint_errors_per_field(): void
    {
        $r = Validator::complaint(['consent' => 'true']);
        $this->assertSame(['name', 'ic', 'phone', 'details', 'location', 'consent'], array_keys($r['errors']));
    }

    public function test_provisional_title(): void
    {
        $this->assertSame('Lampu jalan rosak.', Validator::provisional_title('Lampu jalan rosak. Sudah dua minggu gelap.'));
        $long = str_repeat('perkataan ', 30);
        $title = Validator::provisional_title($long);
        $this->assertLessThanOrEqual(81, mb_strlen($title));
        $this->assertStringEndsWith('…', $title);
    }

    public function test_crypto_roundtrip_and_blind_index(): void
    {
        $c = new Crypto(random_bytes(32));
        $enc = $c->encrypt('900101145678');
        $this->assertStringStartsWith('v1:', $enc);
        $this->assertStringNotContainsString('900101', $enc);
        $this->assertNotSame($enc, $c->encrypt('900101145678'), 'random nonce');
        $this->assertSame('900101145678', $c->decrypt($enc));
        $this->assertSame($c->blind_index('900101145678'), $c->blind_index('900101145678'));
        $this->assertNull($c->encrypt(''));
    }

    public function test_crypto_wrong_key_fails(): void
    {
        $enc = (new Crypto(random_bytes(32)))->encrypt('rahsia');
        $this->expectException(UnexpectedValueException::class);
        (new Crypto(random_bytes(32)))->decrypt($enc);
    }

    public function test_access_rules(): void
    {
        $officer = ['id' => 7, 'manager' => false, 'work' => true, 'policy' => Access::normalize_policy(['mode' => 'selected', 'codes' => ['A01', 'bad']])];
        $this->assertSame(['A01'], $officer['policy']['codes']);
        $this->assertTrue(Access::can_view($officer, ['owner_id' => 0, 'category_code' => 'A01']));
        $this->assertFalse(Access::can_view($officer, ['owner_id' => 0, 'category_code' => 'A02']));
        $this->assertFalse(Access::can_view($officer, ['owner_id' => 0, 'category_code' => null]), 'unclassified is Super Admin only');
        $this->assertTrue(Access::can_view($officer, ['owner_id' => 7, 'category_code' => 'A02']), 'own case always visible');

        $viewer = ['id' => 9, 'manager' => false, 'work' => false, 'policy' => Access::normalize_policy(['mode' => 'all'])];
        $this->assertFalse(Access::can_view($viewer, ['owner_id' => 0, 'category_code' => 'A01']));

        $nopolicy = ['id' => 10, 'manager' => false, 'work' => true, 'policy' => Access::normalize_policy('')];
        $this->assertSame('none', $nopolicy['policy']['mode']);
        $this->assertFalse(Access::can_own($nopolicy, 'A01'), 'missing policy denies');
    }
}
