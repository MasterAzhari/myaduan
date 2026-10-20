<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Finds the encryption key. The key never lives in the database, so a leaked
 * SQL dump alone cannot reveal personal data.
 *
 * Order: MYADUAN_ENC_KEY constant in wp-config.php, then a key file inside the
 * private storage directory (created on activation).
 */
final class Keys
{
    public static function load(): string
    {
        if (defined('MYADUAN_ENC_KEY')) {
            $key = base64_decode((string) MYADUAN_ENC_KEY, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('MYADUAN_ENC_KEY mesti 32 bait dalam base64.');
            }
            return $key;
        }
        $file = self::key_file();
        if (!is_readable($file)) {
            throw new \RuntimeException('Kunci sulitan MyAduan tiada. Aktifkan semula plugin atau tetapkan MYADUAN_ENC_KEY.');
        }
        $key = base64_decode(trim((string) file_get_contents($file)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Fail kunci sulitan MyAduan rosak.');
        }
        return $key;
    }

    /** Creates the key file once. Never overwrites an existing key. */
    public static function ensure(): void
    {
        if (defined('MYADUAN_ENC_KEY')) {
            return;
        }
        $file = self::key_file();
        if (is_file($file)) {
            return;
        }
        Storage::ensure_root();
        $written = file_put_contents($file, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . "\n", LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Tidak dapat menulis fail kunci MyAduan.');
        }
        @chmod($file, 0600);
    }

    private static function key_file(): string
    {
        return Storage::root() . '/.myaduan-key';
    }
}
