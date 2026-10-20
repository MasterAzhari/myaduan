<?php
namespace MyAduan;

/**
 * Encrypts personal fields at rest and builds blind indexes for exact lookups
 * (IC number) without storing the plain value.
 */
final class Crypto
{
    private const PREFIX = 'v1:';

    private string $enc_key;
    private string $index_key;

    public function __construct(string $master_key)
    {
        if (strlen($master_key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Kunci mesti 32 bait.');
        }
        $this->enc_key = hash_hkdf('sha256', $master_key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'myaduan-encrypt');
        $this->index_key = hash_hkdf('sha256', $master_key, 32, 'myaduan-blind-index');
    }

    public function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->enc_key));
    }

    public function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (strncmp($stored, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            throw new \UnexpectedValueException('Format data sulit tidak dikenali.');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \UnexpectedValueException('Data sulit rosak.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->enc_key
        );
        if ($plain === false) {
            throw new \UnexpectedValueException('Data sulit tidak dapat dibuka. Kunci mungkin berubah.');
        }
        return $plain;
    }

    /** Deterministic keyed hash for exact-match lookups. */
    public function blind_index(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, $this->index_key);
    }
}
