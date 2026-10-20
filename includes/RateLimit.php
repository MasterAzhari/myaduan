<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/** Fixed-window limits per client IP, stored as transients (object cache when available). */
final class RateLimit
{
    /** Returns false when the bucket is already full. Does not count the hit. */
    public static function allows(string $bucket, int $max): bool
    {
        return (int) get_transient(self::key($bucket)) < $max;
    }

    public static function hit(string $bucket, int $window): void
    {
        $key = self::key($bucket);
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, $window);
    }

    private static function key(string $bucket): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return 'myaduan_rl_' . $bucket . '_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 24);
    }
}
