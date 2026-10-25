<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/** Activation, upgrades and roles. Safe to run repeatedly. */
final class Installer
{
    public const DB_OPTION = 'myaduan_db_version';

    public const ROLES = [
        'myaduan_superadmin' => ['Super Admin MyAduan', [Access::CAP_ACCESS, Access::CAP_MANAGE, Access::CAP_WORK, Access::CAP_REPORTS]],
        'myaduan_officer' => ['Pegawai MyAduan', [Access::CAP_ACCESS, Access::CAP_WORK]],
        'myaduan_viewer' => ['Pemerhati MyAduan', [Access::CAP_ACCESS, Access::CAP_REPORTS]],
    ];

    public static function activate(): void
    {
        self::install();
    }

    public static function deactivate(): void
    {
        // Data, roles and the key stay. Uninstall is a separate, deliberate step.
        wp_clear_scheduled_hook('myaduan_send_mail');
    }

    public static function maybe_upgrade(): void
    {
        if ((int) get_option(self::DB_OPTION) < MYADUAN_DB_VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (Schema::sql() as $sql) {
            dbDelta($sql);
        }
        self::check_engine();
        Catalog::seed();
        Agencies::seed();
        self::roles();
        Storage::ensure_root();
        Keys::ensure();
        update_option(self::DB_OPTION, MYADUAN_DB_VERSION, false);
    }

    private static function roles(): void
    {
        foreach (self::ROLES as $role => [$label, $caps]) {
            remove_role($role);
            add_role($role, $label, array_fill_keys(array_merge(['read'], $caps), true));
        }
        // Site administrators can always reach the system (to set it up).
        $admin = get_role('administrator');
        if ($admin) {
            foreach (self::ROLES['myaduan_superadmin'][1] as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    /** Transactions and row locks need InnoDB. */
    private static function check_engine(): void
    {
        global $wpdb;
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            Schema::table('cases')
        ));
        if ($engine && strtolower($engine) !== 'innodb') {
            $wpdb->query('ALTER TABLE ' . Schema::table('cases') . ' ENGINE=InnoDB');
            $wpdb->query('ALTER TABLE ' . Schema::table('events') . ' ENGINE=InnoDB');
            $wpdb->query('ALTER TABLE ' . Schema::table('submissions') . ' ENGINE=InnoDB');
        }
    }
}
