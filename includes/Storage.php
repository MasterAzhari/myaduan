<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Private file storage outside the web root. On Hostinger ABSPATH is
 * .../domains/<site>/public_html/, so the default sits one level above it.
 */
final class Storage
{
    public static function root(): string
    {
        if (defined('MYADUAN_STORAGE_DIR')) {
            return rtrim((string) MYADUAN_STORAGE_DIR, '/\\');
        }
        return dirname(rtrim(ABSPATH, '/\\')) . '/myaduan-files';
    }

    public static function ensure_root(): void
    {
        $root = self::root();
        if (!is_dir($root) && !wp_mkdir_p($root)) {
            throw new \RuntimeException('Tidak dapat mencipta folder simpanan MyAduan: ' . $root);
        }
        @chmod($root, 0750);
        // Defence in depth in case the folder ends up web-reachable.
        if (!is_file($root . '/.htaccess')) {
            file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($root . '/index.php')) {
            file_put_contents($root . '/index.php', "<?php\n// Silence.\n");
        }
    }

    /** True when the storage folder is inside the public web root. */
    public static function is_public(): bool
    {
        $root = realpath(self::root());
        $web = realpath(ABSPATH);
        return $root !== false && $web !== false && strncmp($root . '/', $web . '/', strlen($web) + 1) === 0;
    }
}
