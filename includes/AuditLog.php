<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One log for tracing what happened: complaints (new, status checks, every case
 * action), staff and Super Admin actions, logins, e-mail, cron and errors.
 *
 * Shown to nobody in the system. It is written as one JSON line per event to
 * private daily files outside public_html (<storage>/log/myaduan-YYYY-MM-DD.log),
 * for the developer to download from the hosting file manager when tracing a fault.
 *
 * Every MyAduan API request gets a short trace code. It goes into the log row,
 * the X-MyAduan-Trace response header and the text of a system error, so a
 * user who reports "Ralat sistem (Kod: 7KQ2M9XA)" points straight at the row
 * with the full error.
 *
 * What is never written here: request bodies, passwords, and complainant
 * details (name, IC, phone, e-mail). Only safe values and field names.
 */
final class AuditLog
{
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const LEVELS = [self::INFO, self::WARNING, self::ERROR];
    public const AREAS = ['aduan' => 'Aduan', 'admin' => 'Admin', 'login' => 'Log masuk', 'emel' => 'E-mel', 'sistem' => 'Sistem'];

    /** Days a file is kept. */
    public const KEEP_DAYS = 180;
    public const DIR = 'log';
    private const PRUNE_OPTION = 'myaduan_log_pruned';

    /** Request values safe to keep as they are (no complainant details). */
    private const SAFE_KEYS = [
        'op', 'role', 'active', 'category_code', 'dun_slug', 'area_id', 'owner_id', 'priority', 'closure_reason',
        'agency_status', 'agency_ids', 'referral_id', 'reference_no', 'followup_at', 'state', 'dun', 'status', 'host', 'port',
    ];
    /** Staff details, kept only for admin routes. */
    private const STAFF_KEYS = ['name', 'email', 'user', 'to', 'officer', 'policy', 'categories'];
    private const SECRET_KEYS = ['password', 'pass', 'current', 'ic', 'phone', 'keys', 'endpoint', 'client_key'];

    private static ?string $trace = null;
    /** The API request being tracked: written as one row when it ends. */
    private static ?array $request = null;
    private static float $started = 0.0;
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        add_filter('rest_request_before_callbacks', [self::class, 'rest_before'], 10, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'rest_after'], 10, 3);
        add_action('wp_login', [self::class, 'on_login'], 10, 2);
        add_action('wp_login_failed', [self::class, 'on_login_failed'], 10, 2);
        add_action('wp_logout', [self::class, 'on_logout']);
        add_action('set_user_role', [self::class, 'on_role'], 20, 3);
        add_action('myaduan_case_created', [self::class, 'on_created'], 5, 2);
        add_action('myaduan_case_operated', [self::class, 'on_operated'], 5, 6);
        add_action('update_option_' . Settings::OPTION, [self::class, 'on_settings'], 10, 2);
        add_action(Mailer::HOOK, [self::class, 'prune'], 90);
        register_shutdown_function([self::class, 'on_shutdown']);
    }

    /** Short code for this PHP request, e.g. "7KQ2M9XA". */
    public static function trace(): string
    {
        return self::$trace ??= strtoupper(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8));
    }

    /** Test seam: starts a fresh request. */
    public static function reset(): void
    {
        self::$trace = null;
        self::$request = null;
    }

    /**
     * Records something that happened. During an API request it joins that
     * request's row (one row per request); otherwise it is written at once.
     */
    public static function event(string $area, string $action, string $message, ?int $case_id = null, array $context = [], string $level = self::INFO): void
    {
        if (self::$request !== null) {
            $r = &self::$request;
            // The first normal event names the row; problems and later events are kept in its details.
            if (empty($r['named']) && $level === self::INFO) {
                $r['area'] = $area;
                $r['action'] = $action;
                $r['message'] = $message;
                $r['named'] = true;
            } else {
                $r['context']['peristiwa'][] = ['action' => $action, 'message' => $message] + ($context ? ['context' => $context] : []);
                $context = [];
            }
            $r['case_id'] ??= $case_id;
            $r['context'] = array_merge($r['context'], $context);
            $r['level'] = self::worse($r['level'], $level);
            return;
        }
        self::write(['area' => $area, 'action' => $action, 'message' => $message, 'case_id' => $case_id, 'context' => $context, 'level' => $level]);
    }

    /** An unexpected error: full details for the log, a trace code for the user. */
    public static function exception(\Throwable $e, string $area = 'sistem'): void
    {
        $detail = [
            'jenis' => get_class($e),
            'mesej' => mb_substr($e->getMessage(), 0, 500),
            'fail' => self::short_path($e->getFile()) . ':' . $e->getLine(),
            'jejak' => array_slice(array_map(
                static fn(array $f): string => (isset($f['class']) ? $f['class'] . $f['type'] : '') . ($f['function'] ?? '') . (isset($f['file']) ? ' @ ' . self::short_path($f['file']) . ':' . ($f['line'] ?? 0) : ''),
                $e->getTrace()
            ), 0, 12),
        ];
        global $wpdb;
        if ($wpdb instanceof \wpdb && $wpdb->last_error !== '') {
            $detail['db'] = mb_substr($wpdb->last_error, 0, 300);
        }
        if (self::$request !== null) {
            self::$request['context']['ralat'] = $detail;
            self::$request['level'] = self::ERROR;
            return;
        }
        self::write(['area' => $area, 'action' => 'RALAT', 'message' => $detail['jenis'] . ': ' . $detail['mesej'], 'context' => ['ralat' => $detail], 'level' => self::ERROR]);
    }

    /* ---------- API requests ---------- */

    public static function rest_before(mixed $response, mixed $handler, \WP_REST_Request $request): mixed
    {
        $route = $request->get_route();
        if (!str_starts_with($route, '/' . Rest\Controller::NS . '/')) {
            return $response;
        }
        self::$started = microtime(true);
        self::$request = [
            'method' => $request->get_method(),
            'route' => mb_substr(substr($route, strlen(Rest\Controller::NS) + 1), 0, 190),
            'area' => str_starts_with($route, '/' . Rest\Controller::NS . '/admin') ? 'admin' : 'aduan',
            'action' => '',
            'message' => '',
            'case_id' => self::route_case($route),
            'context' => self::params($request),
            'level' => self::INFO,
        ];
        return $response;
    }

    public static function rest_after(mixed $response, mixed $handler, \WP_REST_Request $request): mixed
    {
        if (self::$request === null) {
            return $response;
        }
        if ($response instanceof \WP_Error) {
            $data = $response->get_error_data();
            $status = (int) (is_array($data) ? ($data['status'] ?? 500) : 500);
            self::$request['context']['kod_ralat'] = $response->get_error_code();
            if (is_array($data) && !empty($data['fields'])) {
                self::$request['context']['medan'] = array_keys($data['fields']);
            }
            self::$request['error'] = $response->get_error_message();
        } else {
            $status = $response instanceof \WP_HTTP_Response ? $response->get_status() : 200;
        }
        self::finish($status);
        return $response;
    }

    /** Writes the request's row. Also called by handlers that stream a file and exit. */
    public static function finish(int $status): void
    {
        $r = self::$request;
        if ($r === null) {
            return;
        }
        self::$request = null;
        if ($status >= 500) {
            $r['level'] = self::ERROR;
        } elseif ($status >= 400) {
            $r['level'] = self::worse($r['level'], self::WARNING);
        }
        // Reads that only fill a screen are not worth a row unless they fail.
        if ($status < 400 && $r['method'] === 'GET' && $r['action'] === '' && !self::read_worth_logging($r['route'])) {
            return;
        }
        [$area, $action, $label] = self::describe($r['method'], $r['route'], $r['area']);
        if ($r['action'] === '') {
            $r['area'] = $area;
            $r['action'] = $action;
        }
        $r['message'] = $r['message'] !== '' ? $r['message'] : $label;
        if ($status >= 400) {
            $r['message'] .= ' gagal: ' . ($r['error'] ?? 'HTTP ' . $status);
        }
        unset($r['named'], $r['error']);
        $r['status'] = $status;
        $r['duration_ms'] = (int) round((microtime(true) - self::$started) * 1000);
        self::write($r);
    }

    private static function read_worth_logging(string $route): bool
    {
        return (bool) preg_match('#^/admin/(cases|files)/\d+$#', $route);
    }

    /** @return array{0:string, 1:string, 2:string} area, action, message */
    private static function describe(string $method, string $route, string $area): array
    {
        $map = [
            'POST /complaints' => ['aduan', 'ADUAN_BARU', 'Borang aduan dihantar'],
            'POST /track' => ['aduan', 'SEMAK_STATUS', 'Semakan status aduan'],
            'GET /admin/cases/#' => ['aduan', 'BUKA_KES', 'Buka kes'],
            'GET /admin/files/#' => ['aduan', 'BUKA_FAIL', 'Buka fail kes'],
            'POST /admin/cases/#/operations' => ['aduan', 'TINDAKAN_KES', 'Tindakan kes'],
            'POST /admin/me/password' => ['login', 'TUKAR_KATA_LALUAN', 'Tukar kata laluan sendiri'],
            'POST /admin/users' => ['admin', 'PENGGUNA_BARU', 'Cipta akaun pengguna'],
            'POST /admin/users/#' => ['admin', 'PENGGUNA_KEMASKINI', 'Kemas kini akaun pengguna'],
            'POST /admin/agencies' => ['admin', 'AGENSI_BARU', 'Tambah agensi'],
            'POST /admin/agencies/#' => ['admin', 'AGENSI_KEMASKINI', 'Kemas kini agensi'],
            'POST /admin/areas' => ['admin', 'KAWASAN_TAMBAH', 'Tambah taman/kampung'],
            'POST /admin/areas/#' => ['admin', 'KAWASAN_KEMASKINI', 'Kemas kini taman/kampung'],
            'POST /admin/mail/#/retry' => ['emel', 'EMEL_HANTAR_SEMULA', 'Hantar semula e-mel'],
            'POST /admin/mail/run' => ['emel', 'EMEL_JALAN_SEKARANG', 'Hantar & semak e-mel sekarang'],
            'POST /admin/mail/test' => ['emel', 'EMEL_UJIAN', 'Hantar e-mel ujian'],
            'POST /admin/mailbox' => ['emel', 'MAILBOX_SAMBUNG', 'Simpan sambungan mailbox'],
            'DELETE /admin/mailbox' => ['emel', 'MAILBOX_PUTUS', 'Putuskan sambungan mailbox'],
            'POST /admin/notifications/read' => ['admin', 'NOTIFIKASI_BACA', 'Tanda notifikasi dibaca'],
            'POST /admin/push' => ['admin', 'PUSH_LANGGAN', 'Aktifkan notifikasi push peranti'],
            'DELETE /admin/push' => ['admin', 'PUSH_BATAL', 'Matikan notifikasi push peranti'],
        ];
        $key = $method . ' ' . preg_replace('#/\d+(?=/|$)#', '/#', $route);
        return $map[$key] ?? [$area, 'API', $method . ' ' . $route];
    }

    private static function route_case(string $route): ?int
    {
        return preg_match('#/admin/cases/(\d+)#', $route, $m) ? (int) $m[1] : null;
    }

    /** What the request asked for, without bodies or personal details. */
    private static function params(\WP_REST_Request $request): array
    {
        if ($request->get_method() === 'GET') {
            return [];
        }
        $p = $request->get_json_params() ?: $request->get_body_params();
        if (!is_array($p)) {
            return [];
        }
        $admin = str_contains($request->get_route(), '/admin/');
        $safe = [];
        $given = [];
        foreach ($p as $k => $v) {
            $k = (string) $k;
            if (in_array($k, self::SECRET_KEYS, true)) {
                if ($k === 'password' && $v !== '' && $v !== null) {
                    $safe['kata_laluan'] = 'ditukar';
                }
                continue;
            }
            if (in_array($k, self::SAFE_KEYS, true) || ($admin && in_array($k, self::STAFF_KEYS, true))) {
                $safe[$k] = is_scalar($v) || $v === null ? mb_substr((string) $v, 0, 200) : $v;
            } elseif ($v !== '' && $v !== null && !in_array($k, ['version', 'request_id', 'website', 'consent'], true)) {
                $given[] = $k;
            }
        }
        if ($given) {
            $safe['medan_diisi'] = $given;
        }
        return $safe;
    }

    /* ---------- events from the rest of the plugin ---------- */

    public static function on_created(int $id, string $reference): void
    {
        self::event('aduan', 'ADUAN_BARU', "Aduan baru $reference diterima", $id, ['rujukan' => $reference]);
    }

    public static function on_operated(int $id, string $op, array $actor, string $from, string $to, array $changes): void
    {
        global $wpdb;
        $ref = (string) $wpdb->get_var($wpdb->prepare('SELECT reference FROM ' . Schema::table('cases') . ' WHERE id = %d', $id));
        $label = Workflow::OPERATIONS[$op] ?? $op;
        $label = is_array($label) ? ($label['label'] ?? $op) : (string) $label;
        $msg = "$ref: $label" . ($from !== $to ? " ($from → $to)" : '');
        self::event('aduan', 'KES_' . strtoupper($op), $msg, $id, ['rujukan' => $ref, 'status' => [$from, $to], 'perubahan' => array_keys($changes)]);
    }

    public static function on_login(string $login, \WP_User $user): void
    {
        self::write(['area' => 'login', 'action' => 'LOG_MASUK', 'message' => $user->display_name . ' log masuk', 'actor_id' => (int) $user->ID]);
    }

    public static function on_login_failed(string $login, ?\WP_Error $error = null): void
    {
        $code = $error ? $error->get_error_code() : '';
        $why = match ($code) {
            'incorrect_password' => 'kata laluan salah',
            'invalid_username', 'invalid_email' => 'akaun tidak wujud',
            'myaduan_inactive' => 'akaun tidak aktif',
            default => $code ?: 'tidak diketahui',
        };
        self::write([
            'area' => 'login', 'action' => 'LOG_MASUK_GAGAL', 'level' => self::WARNING,
            'message' => 'Log masuk gagal untuk "' . mb_substr(sanitize_text_field($login), 0, 80) . '": ' . $why,
            'context' => ['kod' => $code],
        ]);
    }

    public static function on_logout(int $user_id = 0): void
    {
        $user = get_userdata($user_id ?: get_current_user_id());
        self::write(['area' => 'login', 'action' => 'LOG_KELUAR', 'message' => ($user ? $user->display_name : 'Pengguna') . ' log keluar', 'actor_id' => $user ? (int) $user->ID : 0]);
    }

    public static function on_role(int $user_id, string $role, array $old_roles): void
    {
        if ($old_roles === [$role]) {
            return;
        }
        $user = get_userdata($user_id);
        self::event('admin', 'TUKAR_PERANAN', sprintf('Peranan %s: %s → %s', $user ? $user->user_email : '#' . $user_id, implode(', ', $old_roles) ?: '(tiada)', $role ?: '(tiada)'), null, ['pengguna' => $user_id]);
    }

    public static function on_settings(mixed $old, mixed $new): void
    {
        $old = is_array($old) ? $old : [];
        $new = is_array($new) ? $new : [];
        $changed = [];
        foreach ($new as $k => $v) {
            if ((string) ($old[$k] ?? '') !== (string) (is_scalar($v) ? $v : wp_json_encode($v))) {
                $changed[$k] = [mb_substr((string) ($old[$k] ?? ''), 0, 120), mb_substr(is_scalar($v) ? (string) $v : (string) wp_json_encode($v), 0, 120)];
            }
        }
        if ($changed) {
            self::event('admin', 'TETAPAN_DIUBAH', 'Tetapan MyAduan diubah: ' . implode(', ', array_keys($changed)), null, ['perubahan' => $changed]);
        }
    }

    /** PHP fatal errors in MyAduan code, or in any MyAduan API request. */
    public static function on_shutdown(): void
    {
        $e = error_get_last();
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if (!$e || !($e['type'] & $fatal)) {
            return;
        }
        $ours = str_starts_with((string) $e['file'], MYADUAN_DIR) || self::$request !== null;
        if (!$ours) {
            return;
        }
        $detail = ['jenis' => 'PHP fatal', 'mesej' => mb_substr((string) $e['message'], 0, 1000), 'fail' => self::short_path((string) $e['file']) . ':' . $e['line']];
        $row = self::$request ?? ['area' => 'sistem', 'action' => 'PHP_FATAL', 'message' => ''];
        self::$request = null;
        $row['action'] = $row['action'] ?: 'PHP_FATAL';
        $row['message'] = 'Ralat PHP: ' . mb_substr((string) $e['message'], 0, 300);
        $row['context'] = array_merge($row['context'] ?? [], ['ralat' => $detail]);
        $row['level'] = self::ERROR;
        $row['status'] = 500;
        try {
            self::write($row);
        } catch (\Throwable) {
            // Nothing more can be done this late.
        }
    }

    /** Daily: removes files older than KEEP_DAYS. */
    public static function prune(): int
    {
        if ((int) get_option(self::PRUNE_OPTION) > time() - DAY_IN_SECONDS) {
            return 0;
        }
        update_option(self::PRUNE_OPTION, time(), false);
        $days = max(30, (int) apply_filters('myaduan_log_keep_days', self::KEEP_DAYS));
        $cutoff = gmdate('Y-m-d', time() - $days * DAY_IN_SECONDS);
        $removed = 0;
        foreach (glob(self::dir() . '/myaduan-*.log') ?: [] as $file) {
            if (preg_match('/myaduan-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m) && $m[1] < $cutoff && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    public static function dir(): string
    {
        return Storage::root() . '/' . self::DIR;
    }

    /** Today's file (dates in Malaysia time, like the portal). */
    public static function file(?int $time = null): string
    {
        return self::dir() . '/myaduan-' . wp_date('Y-m-d', $time ?? time()) . '.log';
    }

    /* ---------- storage ---------- */

    private static function write(array $r): void
    {
        $context = $r['context'] ?? [];
        if (wp_doing_cron() && empty($r['actor_id']) && !get_current_user_id()) {
            $context['cron'] = true;
        }
        $user = (int) ($r['actor_id'] ?? get_current_user_id());
        $row = array_filter([
            'masa' => wp_date('Y-m-d H:i:s'),
            'tahap' => in_array($r['level'] ?? '', self::LEVELS, true) ? $r['level'] : self::INFO,
            'bahagian' => isset(self::AREAS[$r['area'] ?? '']) ? $r['area'] : 'sistem',
            'peristiwa' => mb_substr((string) ($r['action'] ?? ''), 0, 48),
            'mesej' => mb_substr((string) ($r['message'] ?? ''), 0, 500),
            'oleh' => $user ? self::who($user) : null,
            'kes' => isset($r['case_id']) ? (int) $r['case_id'] : null,
            'kod' => self::trace(),
            'ip' => self::ip() ?: null,
            'permintaan' => isset($r['route']) ? trim(($r['method'] ?? '') . ' ' . $r['route']) : null,
            'http' => isset($r['status']) ? (int) $r['status'] : null,
            'ms' => isset($r['duration_ms']) ? (int) $r['duration_ms'] : null,
            'butiran' => $context ?: null,
        ], static fn($v): bool => $v !== null && $v !== '');
        $line = wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!$line) {
            return;
        }
        // A log that cannot be written must never break the request it describes.
        try {
            $dir = self::dir();
            if (!is_dir($dir)) {
                Storage::ensure_root();
                if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                    return;
                }
                @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
                @file_put_contents($dir . '/index.php', "<?php\n// Silence.\n");
            }
            @file_put_contents(self::file(), mb_substr($line, 0, 20000) . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
        }
    }

    /** "Name <email> #id", cached per request. */
    private static function who(int $id): string
    {
        static $names = [];
        if (!isset($names[$id])) {
            $u = get_userdata($id);
            $names[$id] = $u ? "{$u->display_name} <{$u->user_email}> #$id" : "#$id";
        }
        return $names[$id];
    }

    private static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private static function short_path(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        foreach ([str_replace('\\', '/', MYADUAN_DIR) . '/' => 'myaduan/', str_replace('\\', '/', ABSPATH) => ''] as $root => $as) {
            if (str_starts_with($file, $root)) {
                return $as . substr($file, strlen($root));
            }
        }
        return basename($file);
    }

    private static function worse(string $a, string $b): string
    {
        return array_search($a, self::LEVELS, true) >= array_search($b, self::LEVELS, true) ? $a : $b;
    }

}
