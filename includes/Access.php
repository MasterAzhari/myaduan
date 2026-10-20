<?php
namespace MyAduan;

/**
 * Who can see and work which cases.
 *
 * - Super Admin (cap myaduan_manage): everything, including unclassified cases.
 * - Pegawai (cap myaduan_work_cases): every case in their permitted categories,
 *   plus any case assigned to them; may change only cases assigned to them.
 * - Pemerhati (cap myaduan_view_reports only): dashboard numbers, no case detail.
 */
final class Access
{
    public const CAP_ACCESS = 'myaduan_access';
    public const CAP_MANAGE = 'myaduan_manage';
    public const CAP_WORK = 'myaduan_work_cases';
    public const CAP_REPORTS = 'myaduan_view_reports';
    public const POLICY_META = 'myaduan_categories';

    /** @return array{mode:string, codes:list<string>} */
    public static function normalize_policy(mixed $raw): array
    {
        if (!is_array($raw) || !in_array($raw['mode'] ?? null, ['all', 'selected'], true)) {
            return ['mode' => 'none', 'codes' => []];
        }
        $codes = array_values(array_unique(array_filter(
            array_map('strval', (array) ($raw['codes'] ?? [])),
            static fn(string $c): bool => (bool) preg_match('/^[A-Z]\d{2}$/D', $c)
        )));
        return ['mode' => $raw['mode'], 'codes' => $raw['mode'] === 'all' ? [] : $codes];
    }

    public static function policy_allows(array $policy, ?string $category): bool
    {
        if ($category === null || $category === '') {
            return false; // Unclassified intake is triaged by Super Admin only.
        }
        return match ($policy['mode'] ?? 'none') {
            'all' => true,
            'selected' => in_array($category, $policy['codes'], true),
            default => false,
        };
    }

    /**
     * @param array{id:int, manager:bool, work:bool, policy:array} $actor
     * @param array{owner_id:int, category_code:?string} $case
     */
    public static function can_view(array $actor, array $case): bool
    {
        if ($actor['manager']) {
            return true;
        }
        if (!$actor['work']) {
            return false;
        }
        if ((int) $case['owner_id'] === (int) $actor['id'] && $actor['id'] > 0) {
            return true;
        }
        return self::policy_allows($actor['policy'], $case['category_code']);
    }

    /** Whether a user may be made owner of a case in this category. */
    public static function can_own(array $actor, ?string $category): bool
    {
        return $actor['manager'] || ($actor['work'] && self::policy_allows($actor['policy'], $category));
    }

    /** Builds the actor array for a WordPress user. */
    public static function actor(?\WP_User $user = null): array
    {
        $user ??= wp_get_current_user();
        $id = (int) $user->ID;
        return [
            'id' => $id,
            'name' => $id ? $user->display_name : '',
            'manager' => $id > 0 && user_can($user, self::CAP_MANAGE),
            'work' => $id > 0 && user_can($user, self::CAP_WORK),
            'reports' => $id > 0 && (user_can($user, self::CAP_REPORTS) || user_can($user, self::CAP_MANAGE)),
            'policy' => self::normalize_policy($id ? get_user_meta($id, self::POLICY_META, true) : null),
        ];
    }
}
