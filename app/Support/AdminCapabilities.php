<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Central registry of admin capabilities and the named presets that bundle
 * them. Permissions are stored as a JSON array on users.permissions and
 * checked via User::hasPermission(). Super Admins implicitly have all.
 */
final class AdminCapabilities
{
    // Compliance
    public const APPROVE_SBOS = 'approve_sbos';

    public const ASSIGN_SBO_MANAGERS = 'assign_sbo_managers';

    // Content / Directory
    public const MANAGE_BUSINESSES = 'manage_businesses';

    public const MANAGE_CATEGORIES = 'manage_categories';

    public const MODERATE_REVIEWS = 'moderate_reviews';

    // Growth: manage zone referral links and view registration momentum.
    public const MANAGE_ZONE_REFERRALS = 'manage_zone_referrals';

    public const VIEW_SBO_PAYMENTS = 'view_sbo_payments';

    // Ads / Revenue
    public const MANAGE_ADS = 'manage_ads';

    // Procurement
    public const MANAGE_RFQS = 'manage_rfqs';

    public const AWARD_BIDS = 'award_bids';

    // Reports
    public const MANAGE_REPORTS = 'manage_reports';

    // Users / Admins
    public const MANAGE_USERS = 'manage_users';

    public const MANAGE_ADMINS = 'manage_admins';

    // Oversight: can view the platform-wide activity / audit log.
    public const VIEW_AUDIT_LOG = 'view_audit_log';

    // Baseline: can view the admin area (read-only support).
    public const VIEW_ADMIN = 'view_admin';

    /**
     * All capabilities with human labels, grouped for the admin UI.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        return [
            'Compliance' => [
                self::APPROVE_SBOS => 'Approve / decline SBOs',
                self::ASSIGN_SBO_MANAGERS => 'Assign SBO managers',
            ],
            'Content & Directory' => [
                self::MANAGE_BUSINESSES => 'Manage SBOs',
                self::MANAGE_CATEGORIES => 'Manage categories & locations',
                self::MODERATE_REVIEWS => 'Moderate reviews',
            ],
            'Growth' => [
                self::MANAGE_ZONE_REFERRALS => 'Manage zone referral links & momentum',
                self::VIEW_SBO_PAYMENTS => 'View SBO payment history',
            ],
            'Ads & Revenue' => [
                self::MANAGE_ADS => 'Manage ad placements',
            ],
            'Procurement' => [
                self::MANAGE_RFQS => 'Manage Procurement Requests',
                self::AWARD_BIDS => 'Award bids',
            ],
            'Reports' => [
                self::MANAGE_REPORTS => 'Manage reports',
            ],
            'Users & Admins' => [
                self::MANAGE_USERS => 'Manage users',
                self::MANAGE_ADMINS => 'Manage administrators',
            ],
            'Oversight' => [
                self::VIEW_AUDIT_LOG => 'View activity / audit log',
            ],
            'General' => [
                self::VIEW_ADMIN => 'View admin area (read-only)',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        $all = [];

        foreach (self::grouped() as $caps) {
            $all = [...$all, ...array_keys($caps)];
        }

        return $all;
    }

    /**
     * Named presets → bundled capabilities.
     *
     * @return array<string, array{label: string, permissions: array<int, string>}>
     */
    public static function presets(): array
    {
        return [
            'compliance' => [
                'label' => 'Compliance Admin',
                'permissions' => [
                    self::VIEW_ADMIN,
                    self::APPROVE_SBOS,
                    self::ASSIGN_SBO_MANAGERS,
                ],
            ],
            'content' => [
                'label' => 'Content / Directory Admin',
                'permissions' => [
                    self::VIEW_ADMIN,
                    self::MANAGE_BUSINESSES,
                    self::MANAGE_CATEGORIES,
                    self::MODERATE_REVIEWS,
                    self::MANAGE_ZONE_REFERRALS,
                ],
            ],
            'ads' => [
                'label' => 'Ads / Revenue Admin',
                'permissions' => [
                    self::VIEW_ADMIN,
                    self::MANAGE_ADS,
                ],
            ],
            'procurement' => [
                'label' => 'Procurement Admin',
                'permissions' => [
                    self::VIEW_ADMIN,
                    self::MANAGE_RFQS,
                    self::AWARD_BIDS,
                ],
            ],
            'auditor' => [
                'label' => 'Audit / Compliance Officer',
                'permissions' => [
                    self::VIEW_ADMIN,
                    self::VIEW_AUDIT_LOG,
                ],
            ],
            'support' => [
                'label' => 'Support / Read-only Admin',
                'permissions' => [
                    self::VIEW_ADMIN,
                ],
            ],
        ];
    }

    /**
     * Infer a human "admin type" label from a permission set.
     *
     * Returns the preset label when the permissions exactly match a preset;
     * otherwise "Custom" when capabilities exist, or "No access" when empty.
     *
     * @param  array<int, string>|null  $permissions
     */
    public static function typeLabel(?array $permissions): string
    {
        $permissions = array_values(array_unique($permissions ?? []));
        sort($permissions);

        foreach (self::presets() as $preset) {
            $presetPerms = array_values(array_unique($preset['permissions']));
            sort($presetPerms);

            if ($permissions === $presetPerms) {
                return $preset['label'];
            }
        }

        return [] === $permissions ? 'No access' : 'Custom';
    }
}
