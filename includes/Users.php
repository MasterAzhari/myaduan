<?php
namespace MyAduan;

use MyAduan\Rest\FieldErrors;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MyAduan accounts managed from the portal by a Super Admin.
 *
 * Site administrators are listed but stay read-only here: they are managed in
 * WordPress itself. Accounts with a staff e-mail but no MyAduan role (made in
 * wp-admin, say) are listed too, so a Super Admin can give them a role here. Deactivating removes the role (and every session) but keeps
 * the account, so case history still shows who did what.
 *
 * The Super Admin sets each account's first password and resets forgotten ones.
 * Everyone changes their own password in the portal; e-mailed reset links are off.
 */
final class Users
{
    public const INACTIVE_META = 'myaduan_inactive';
    public const ROLES = ['superadmin' => 'myaduan_superadmin', 'officer' => 'myaduan_officer', 'viewer' => 'myaduan_viewer'];

    public function __construct(private \wpdb $db, private Catalog $catalog)
    {
    }

    public function list(): array
    {
        $ids = array_merge(
            get_users(['role__in' => array_merge(array_values(self::ROLES), ['administrator']), 'fields' => 'ID']),
            get_users(['meta_key' => self::INACTIVE_META, 'fields' => 'ID']),
            get_users(['search' => '*@' . Settings::staff_domain(), 'search_columns' => ['user_email'], 'fields' => 'ID'])
        );
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $open = $this->open_cases($ids);
        $users = get_users(['include' => $ids, 'orderby' => 'display_name']);
        return array_map(fn(\WP_User $u): array => $this->shape($u, $open[$u->ID] ?? []), $users);
    }

    public const PASSWORD_MIN = 8;

    /** @param array{name?:mixed, email?:mixed, password?:mixed, role?:mixed, policy?:mixed} $in */
    public function create(array $in): array
    {
        $errors = [];
        $name = $this->name($in['name'] ?? '', $errors);
        $email = $this->email($in['email'] ?? '', 0, $errors);
        $password = $this->password($in['password'] ?? '', $errors);
        $role = $this->role($in['role'] ?? '', $errors);
        $policy = $role === 'officer' ? $this->policy($in['policy'] ?? null, $errors) : null;
        if ($errors) {
            throw new FieldErrors($errors);
        }
        $id = wp_insert_user([
            'user_login' => $this->unique_login($email),
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => $name,
            'first_name' => $name,
            'role' => self::ROLES[$role],
        ]);
        if (is_wp_error($id)) {
            throw new FieldErrors(['email' => $id->get_error_message()]);
        }
        $policy ? update_user_meta($id, Access::POLICY_META, $policy) : delete_user_meta($id, Access::POLICY_META);
        return $this->shape(new \WP_User($id), []);
    }

    /** @param array{name?:mixed, email?:mixed, password?:mixed, role?:mixed, policy?:mixed, active?:mixed} $in */
    public function update(int $id, array $in, int $actor_id): array
    {
        $user = get_userdata($id);
        if (!$user || !$this->is_listed($user)) {
            throw new WorkflowException('Pengguna tidak ditemui.', 'not_found', 404);
        }
        if (user_can($user, 'manage_options')) {
            throw new WorkflowException('Akaun pentadbir laman diurus dalam WordPress, bukan di sini.', 'forbidden', 403);
        }
        $current = $this->shape($user, []);
        $errors = [];
        $name = array_key_exists('name', $in) ? $this->name($in['name'], $errors) : $current['name'];
        // The effective address is always checked, so an old non-@myaduan.my account must be moved
        // before any other change saves. Only deactivating such an account is allowed as is.
        $only_deactivate = array_keys($in) === ['active'] && !filter_var($in['active'], FILTER_VALIDATE_BOOLEAN);
        $email = $only_deactivate ? $current['email'] : $this->email($in['email'] ?? $current['email'], $id, $errors);
        // A new password from the Super Admin, for someone who forgot theirs. Blank keeps the current one.
        $password = isset($in['password']) && $in['password'] !== '' ? $this->password($in['password'], $errors) : null;
        if ($password !== null && $id === $actor_id) {
            $errors['password'] = 'Tukar kata laluan anda sendiri di halaman Profil.';
        }
        $role = array_key_exists('role', $in) ? $this->role($in['role'], $errors) : $current['role'];
        // An account getting its first role turns active unless told otherwise.
        $active = array_key_exists('active', $in) ? filter_var($in['active'], FILTER_VALIDATE_BOOLEAN) : ($current['active'] || $current['role'] === 'none');
        if ($role === 'none') {
            $errors['role'] = 'Pilih peranan.';
        }
        $policy = null;
        if ($role === 'officer') {
            $policy = array_key_exists('policy', $in) || $current['role'] !== 'officer'
                ? $this->policy($in['policy'] ?? null, $errors)
                : $current['policy'];
        }
        if ($errors) {
            throw new FieldErrors($errors);
        }
        if ($id === $actor_id && ($role !== $current['role'] || !$active)) {
            throw new WorkflowException('Anda tidak boleh menukar peranan atau menyahaktifkan akaun sendiri.', 'forbidden', 403);
        }

        // Every open case this user holds must still be theirs to work after the change.
        $after = ['id' => $id, 'manager' => $active && $role === 'superadmin', 'work' => $active && $role !== 'viewer', 'policy' => Access::normalize_policy($policy)];
        $blocked = array_filter($this->open_cases([$id])[$id] ?? [], static fn(?string $category): bool => !Access::can_own($after, $category));
        if ($blocked) {
            throw new WorkflowException(sprintf(
                'Pengguna ini masih memegang %d kes terbuka yang tidak lagi dibenarkan selepas perubahan ini. Tugaskan semula kes tersebut dahulu.',
                count($blocked)
            ), 'has_open_cases', 409);
        }

        $updated = wp_update_user(['ID' => $id, 'display_name' => $name, 'user_email' => $email]);
        if (is_wp_error($updated)) {
            throw new FieldErrors(['email' => $updated->get_error_message()]);
        }
        if ($password !== null) {
            // Signs the user out everywhere, so only the new password works from now on.
            wp_set_password($password, $id);
            \WP_Session_Tokens::get_instance($id)->destroy_all();
        }
        // Only MyAduan roles change; any other WordPress role the account has stays.
        foreach (self::ROLES as $wp_role) {
            $user->remove_role($wp_role);
        }
        if ($active) {
            $user->add_role(self::ROLES[$role]);
            delete_user_meta($id, self::INACTIVE_META);
        } else {
            update_user_meta($id, self::INACTIVE_META, self::ROLES[$role]);
            \WP_Session_Tokens::get_instance($id)->destroy_all();
        }
        $policy ? update_user_meta($id, Access::POLICY_META, $policy) : delete_user_meta($id, Access::POLICY_META);
        clean_user_cache($id);
        return $this->shape(get_userdata($id), $this->open_cases([$id])[$id] ?? []);
    }

    /**
     * The signed-in user changes their own password. The other sessions end;
     * this one gets a fresh login cookie, so the portal reloads afterwards.
     */
    public function change_own_password(int $id, mixed $current, mixed $new): void
    {
        $user = get_userdata($id);
        $errors = [];
        if (!$user || !wp_check_password((string) $current, $user->user_pass, $id)) {
            $errors['current'] = 'Kata laluan semasa tidak betul.';
        }
        $password = $this->password($new, $errors);
        if (!$errors && $password === (string) $current) {
            $errors['password'] = 'Kata laluan baru mesti berbeza daripada yang semasa.';
        }
        if ($errors) {
            throw new FieldErrors($errors);
        }
        \WP_Session_Tokens::get_instance($id)->destroy_all();
        // wp_update_user() sets a new login cookie when it is the current user's password.
        $done = wp_update_user(['ID' => $id, 'user_pass' => $password]);
        if (is_wp_error($done)) {
            throw new FieldErrors(['password' => $done->get_error_message()]);
        }
    }

    /** MyAduan accounts other than site administrators. */
    public static function is_staff_account(\WP_User $u): bool
    {
        return !user_can($u, 'manage_options') && (
            (bool) array_intersect($u->roles, array_values(self::ROLES))
            || get_user_meta($u->ID, self::INACTIVE_META, true) !== ''
            || Settings::is_staff_email($u->user_email)
        );
    }

    /** allow_password_reset: no e-mailed reset links for MyAduan accounts; the Super Admin sets a new password. */
    public static function no_email_reset(bool|\WP_Error $allow, int $user_id): bool|\WP_Error
    {
        $user = get_userdata($user_id);
        if ($user && self::is_staff_account($user)) {
            return new \WP_Error('myaduan_no_reset', 'Lupa kata laluan? Minta Super Admin MyAduan tetapkan kata laluan baru untuk anda.');
        }
        return $allow;
    }

    /** user_profile_update_errors: a MyAduan account keeps its @myaduan.my address, wherever it is edited. */
    public static function profile_errors(\WP_Error $errors, bool $update, \stdClass $user): void
    {
        $existing = !empty($user->ID) ? get_userdata((int) $user->ID) : false;
        if ($existing && self::is_staff_account($existing) && isset($user->user_email) && !Settings::is_staff_email((string) $user->user_email)) {
            $errors->add('email', 'Akaun MyAduan mesti guna e-mel @' . Settings::staff_domain() . '.');
        }
    }

    /**
     * wp_authenticate_user: a deactivated MyAduan-only account cannot log in again.
     * Accounts that still hold another WordPress role keep that access.
     */
    public static function block_inactive(\WP_User|\WP_Error $user): \WP_User|\WP_Error
    {
        if ($user instanceof \WP_User && !$user->roles && get_user_meta($user->ID, self::INACTIVE_META, true) !== '') {
            return new \WP_Error('myaduan_inactive', 'Akaun ini telah dinyahaktifkan. Hubungi Super Admin MyAduan.');
        }
        return $user;
    }

    private function shape(\WP_User $u, array $open): array
    {
        $inactive = (string) get_user_meta($u->ID, self::INACTIVE_META, true);
        $role = 'none';
        foreach (self::ROLES as $key => $wp_role) {
            if (in_array($wp_role, $u->roles, true) || $inactive === $wp_role) {
                $role = $key;
            }
        }
        $site_admin = user_can($u, 'manage_options');
        if ($site_admin) {
            $role = 'superadmin';
        }
        return [
            'id' => (int) $u->ID,
            'login' => $u->user_login,
            'name' => $u->display_name,
            'email' => $u->user_email,
            'role' => $role,
            'active' => $site_admin || ($inactive === '' && $role !== 'none'),
            'needs_role' => !$site_admin && $role === 'none',
            'site_admin' => $site_admin,
            'email_ok' => Settings::is_staff_email($u->user_email),
            'policy' => Access::normalize_policy(get_user_meta($u->ID, Access::POLICY_META, true)),
            'open_cases' => count($open),
        ];
    }

    private function is_listed(\WP_User $u): bool
    {
        return (bool) array_intersect($u->roles, array_values(self::ROLES))
            || get_user_meta($u->ID, self::INACTIVE_META, true) !== ''
            || Settings::is_staff_email($u->user_email);
    }

    /** @return array<int, list<?string>> open case categories per owner */
    private function open_cases(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) {
            return [];
        }
        $rows = $this->db->get_results(
            'SELECT owner_id, category_code FROM ' . Schema::table('cases') . " WHERE status <> 'DITUTUP' AND owner_id IN (" . implode(',', $ids) . ')',
            ARRAY_A
        );
        $by_owner = [];
        foreach ($rows as $r) {
            $by_owner[(int) $r['owner_id']][] = $r['category_code'];
        }
        return $by_owner;
    }

    private function name(mixed $value, array &$errors): string
    {
        $name = trim(sanitize_text_field((string) $value));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'Masukkan nama (2 hingga 100 aksara).';
        }
        return $name;
    }

    private function email(mixed $value, int $self_id, array &$errors): string
    {
        $email = sanitize_email((string) $value);
        if (!is_email($email)) {
            $errors['email'] = 'Masukkan alamat e-mel yang sah.';
        } elseif (!Settings::is_staff_email($email)) {
            $errors['email'] = 'Akaun MyAduan mesti guna e-mel @' . Settings::staff_domain() . '.';
        } elseif (($owner = email_exists($email)) && (int) $owner !== $self_id) {
            $errors['email'] = $self_id
                ? 'E-mel ini sudah digunakan oleh akaun lain.'
                : 'Akaun dengan e-mel ini sudah wujud. Cari namanya dalam senarai di bawah dan tekan Ubah untuk beri peranan.';
        }
        return $email;
    }

    private function password(mixed $value, array &$errors): string
    {
        $password = is_string($value) ? $value : '';
        if (mb_strlen($password) < self::PASSWORD_MIN || mb_strlen($password) > 100 || !preg_match('/\pL/u', $password) || !preg_match('/\d/', $password)) {
            $errors['password'] = 'Kata laluan mesti sekurang-kurangnya ' . self::PASSWORD_MIN . ' aksara, dengan huruf dan nombor.';
        } elseif (trim($password) !== $password) {
            $errors['password'] = 'Kata laluan tidak boleh bermula atau berakhir dengan ruang kosong.';
        }
        return $password;
    }

    private function role(mixed $value, array &$errors): string
    {
        $role = (string) $value;
        if (!isset(self::ROLES[$role])) {
            $errors['role'] = 'Pilih peranan.';
            return 'none';
        }
        return $role;
    }

    private function policy(mixed $value, array &$errors): ?array
    {
        $policy = Access::normalize_policy(is_array($value) ? $value : null);
        if ($policy['mode'] === 'selected') {
            $policy['codes'] = array_values(array_filter($policy['codes'], [$this->catalog, 'has_category']));
        }
        if ($policy['mode'] === 'none' || ($policy['mode'] === 'selected' && !$policy['codes'])) {
            $errors['policy'] = 'Pilih semua kategori atau sekurang-kurangnya satu kategori.';
            return null;
        }
        return $policy;
    }

    private function unique_login(string $email): string
    {
        $base = sanitize_user(strtolower(strstr($email, '@', true)), true) ?: 'pegawai';
        $base = substr($base, 0, 50);
        $login = $base;
        for ($i = 2; username_exists($login); $i++) {
            $login = $base . $i;
        }
        return $login;
    }
}
