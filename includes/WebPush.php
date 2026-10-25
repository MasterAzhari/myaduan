<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Web Push without a third-party library: VAPID (RFC 8292) with an ES256 JWT,
 * and payload encryption with aes128gcm (RFC 8188 / RFC 8291), all on openssl.
 * The server key pair is made on first use and kept in an option.
 */
final class WebPush
{
    private const OPTION = 'myaduan_vapid';
    /** DER prefix of a P-256 SubjectPublicKeyInfo; the 65-byte public point follows. */
    private const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    /** Push services a subscription may point at. Anything else is refused, so the server cannot be aimed at other hosts. */
    private const HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', 'push.apple.com', 'notify.windows.com', 'push.services.mozilla.com'];

    /** The application server key for PushManager.subscribe(), base64url. */
    public static function public_key(): string
    {
        return self::b64u(self::keys()['public']);
    }

    /** Whether a subscription's p256dh is a real P-256 point (ECDH with it works). */
    public static function valid_public_key(string $raw): bool
    {
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
            return false;
        }
        $key = @openssl_pkey_get_public(self::pem(hex2bin(self::P256_SPKI) . $raw));
        $probe = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        return $key !== false && @openssl_pkey_derive($key, $probe, 32) !== false;
    }

    public static function allowed_endpoint(string $endpoint): bool
    {
        $parts = wp_parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || strlen($endpoint) > 1000) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return (bool) apply_filters('myaduan_push_endpoint_allowed', false, $endpoint);
    }

    /**
     * Sends one message.
     *
     * @return int HTTP status, 0 on a network error. 404 and 410 mean the subscription is gone.
     */
    public static function send(string $endpoint, string $p256dh, string $auth, string $payload, int $ttl = 86400): int
    {
        if (!self::allowed_endpoint($endpoint)) {
            return 0;
        }
        $parts = wp_parse_url($endpoint);
        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $body = self::encrypt($payload, self::b64u_decode($p256dh), self::b64u_decode($auth));
        $response = wp_remote_post($endpoint, [
            'timeout' => 10,
            'headers' => [
                'TTL' => (string) $ttl,
                'Urgency' => 'high',
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'Authorization' => 'vapid t=' . self::jwt($audience) . ', k=' . self::public_key(),
            ],
            'body' => $body,
        ]);
        return is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    }

    /** RFC 8291: encrypts for a subscription's public key and auth secret. */
    public static function encrypt(string $payload, string $ua_public, string $auth_secret, ?string $salt = null, $as_key = null): string
    {
        if (strlen($ua_public) !== 65 || $ua_public[0] !== "\x04" || strlen($auth_secret) !== 16) {
            throw new \InvalidArgumentException('Kunci langganan push tidak sah.');
        }
        $as_key ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $as_public = self::raw_public($as_key);
        $ua_key = openssl_pkey_get_public(self::pem(hex2bin(self::P256_SPKI) . $ua_public));
        $shared = openssl_pkey_derive($ua_key, $as_key, 32);
        if ($shared === false) {
            throw new \RuntimeException('ECDH gagal.');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua_public . $as_public, $auth_secret);
        $salt ??= random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        // One record: the payload, then the 0x02 "last record" delimiter.
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        return $salt . pack('N', 4096) . chr(65) . $as_public . $cipher . $tag;
    }

    /** VAPID JWT, valid for 12 hours. */
    public static function jwt(string $audience): string
    {
        $head = self::b64u(wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64u(wp_json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . Settings::mail_from()]));
        openssl_sign("$head.$claims", $der, openssl_pkey_get_private(self::keys()['private']), OPENSSL_ALGO_SHA256);
        return "$head.$claims." . self::b64u(self::der_to_raw($der));
    }

    /** @return array{private:string, public:string} PEM private key and raw 65-byte public key */
    private static function keys(): array
    {
        $saved = get_option(self::OPTION);
        if (is_array($saved) && !empty($saved['private'])) {
            $key = openssl_pkey_get_private($saved['private']);
            if ($key) {
                return ['private' => $saved['private'], 'public' => self::raw_public($key)];
            }
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        update_option(self::OPTION, ['private' => $pem], false);
        return ['private' => $pem, 'public' => self::raw_public($key)];
    }

    private static function raw_public($key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];
        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** An ECDSA DER signature to the 64-byte r||s form JWS expects. */
    private static function der_to_raw(string $der): string
    {
        $pos = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64u_decode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
