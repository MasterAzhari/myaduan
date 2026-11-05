<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only access to the private system log through the WordPress Abilities
 * API (WordPress 6.9+), so the developer's MCP client (WordPress MCP Adapter)
 * can read it when troubleshooting. Nothing is shown in the portal or wp-admin.
 *
 * Only a site administrator (manage_options) may run these, normally through
 * an Application Password on the developer's own computer.
 */
final class LogAbilities
{
    public const CATEGORY = 'myaduan';
    private const MAX_BYTES = 20 * 1024 * 1024;

    public static function register(): void
    {
        add_action('wp_abilities_api_categories_init', [self::class, 'category']);
        add_action('wp_abilities_api_init', [self::class, 'abilities']);
    }

    public static function category(): void
    {
        if (function_exists('wp_register_ability_category')) {
            wp_register_ability_category(self::CATEGORY, [
                'label' => 'MyAduan',
                'description' => 'Alat troubleshoot sistem aduan MyAduan.',
            ]);
        }
    }

    public static function abilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        $meta = ['show_in_rest' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true], 'mcp' => ['public' => true]];
        $allowed = static fn(): bool => current_user_can('manage_options');

        wp_register_ability('myaduan/log-files', [
            'label' => 'Senarai fail log MyAduan',
            'description' => 'Senarai fail log harian MyAduan (tarikh, saiz, bilangan baris, bilangan amaran dan ralat). Guna dulu untuk tahu hari mana ada masalah.',
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 180, 'default' => 14, 'description' => 'Berapa hari ke belakang.']],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static fn($input = []): array => self::files((int) (($input['days'] ?? 14) ?: 14)),
            'permission_callback' => $allowed,
            'meta' => $meta,
        ]);

        wp_register_ability('myaduan/read-log', [
            'label' => 'Baca log MyAduan',
            'description' => 'Baca rekod log sistem MyAduan (aduan, tindakan kes, tindakan admin, log masuk, e-mel, ralat), terbaru dahulu. '
                . 'Tapis ikut tarikh, tahap, kod ralat (kod yang pengguna nampak dalam "Ralat sistem (Kod: ...)"), ID kes, peristiwa atau teks.',
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => 'Tarikh YYYY-MM-DD (waktu Malaysia). Kosong = hari ini.'],
                    'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 31, 'default' => 1, 'description' => 'Bilangan hari berakhir pada tarikh itu.'],
                    'level' => ['type' => 'string', 'enum' => ['', 'info', 'warning', 'error', 'problems'], 'description' => 'problems = warning + error.'],
                    'kod' => ['type' => 'string', 'description' => 'Kod jejak 8 huruf.'],
                    'kes' => ['type' => 'integer', 'description' => 'ID kes.'],
                    'peristiwa' => ['type' => 'string', 'description' => 'Contoh: ADUAN_BARU, KES_ASSIGN, EMEL_GAGAL, LOG_MASUK_GAGAL.'],
                    'q' => ['type' => 'string', 'description' => 'Cari teks dalam baris (contoh nombor rujukan GOM-..., e-mel staf, IP).'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 200],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'execute_callback' => static fn($input = []): array => self::read(is_array($input) ? $input : []),
            'permission_callback' => $allowed,
            'meta' => $meta,
        ]);
    }

    /** @return array{folder:string, files:list<array>} */
    public static function files(int $days = 14): array
    {
        $days = max(1, min(180, $days));
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = wp_date('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $path = self::path($date);
            if (!is_file($path)) {
                continue;
            }
            $counts = ['baris' => 0, 'warning' => 0, 'error' => 0];
            foreach (self::lines($path) as $line) {
                $counts['baris']++;
                if (str_contains($line, '"tahap":"error"')) {
                    $counts['error']++;
                } elseif (str_contains($line, '"tahap":"warning"')) {
                    $counts['warning']++;
                }
            }
            $out[] = ['tarikh' => $date, 'saiz' => filesize($path)] + $counts;
        }
        return ['folder' => AuditLog::dir(), 'files' => $out];
    }

    /** @return array{entries:list<array>, matched:int, returned:int, dates:list<string>} */
    public static function read(array $in): array
    {
        $date = (string) ($in['date'] ?? '');
        $end = $date !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone()) : new \DateTimeImmutable('now', wp_timezone());
        if (!$end) {
            throw new \InvalidArgumentException('Tarikh mesti YYYY-MM-DD.');
        }
        $days = max(1, min(31, (int) ($in['days'] ?? 1)));
        $limit = max(1, min(1000, (int) ($in['limit'] ?? 200)));
        $level = (string) ($in['level'] ?? '');
        $kod = strtoupper(trim((string) ($in['kod'] ?? '')));
        $kes = (int) ($in['kes'] ?? 0);
        $event = strtoupper(trim((string) ($in['peristiwa'] ?? '')));
        $q = trim((string) ($in['q'] ?? ''));

        $entries = [];
        $matched = 0;
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $end->modify("-$i day")->format('Y-m-d');
            $path = self::path($d);
            if (!is_file($path)) {
                continue;
            }
            $dates[] = $d;
            // Newest first.
            foreach (array_reverse(self::lines($path)) as $line) {
                if ($q !== '' && stripos($line, $q) === false) {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $t = $row['tahap'] ?? 'info';
                if ($level === 'problems' ? $t === 'info' : ($level !== '' && $t !== $level)) {
                    continue;
                }
                if (($kod !== '' && ($row['kod'] ?? '') !== $kod) || ($kes && (int) ($row['kes'] ?? 0) !== $kes) || ($event !== '' && ($row['peristiwa'] ?? '') !== $event)) {
                    continue;
                }
                $matched++;
                if (count($entries) < $limit) {
                    $entries[] = $row;
                }
            }
        }
        return ['entries' => $entries, 'matched' => $matched, 'returned' => count($entries), 'dates' => $dates];
    }

    private static function path(string $date): string
    {
        return AuditLog::dir() . '/myaduan-' . $date . '.log';
    }

    /** @return list<string> the file's lines; for a very large file, only its last part. */
    private static function lines(string $path): array
    {
        $size = (int) filesize($path);
        $fh = fopen($path, 'r');
        if (!$fh) {
            return [];
        }
        if ($size > self::MAX_BYTES) {
            fseek($fh, $size - self::MAX_BYTES);
            fgets($fh); // drop the partial first line
        }
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        return array_values(array_filter(explode("\n", $text), static fn(string $l): bool => $l !== ''));
    }
}
