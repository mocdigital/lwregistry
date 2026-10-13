<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class AdminPages
{
    public const DASHBOARD = 'dashboard';

    public const USERS = 'users';

    public const BUSINESSES = 'businesses';

    public const BUSINESS_DETAIL = 'business-detail';

    public const COB_BUSINESSES = 'cob-businesses';

    public const ORGANIZATIONS = 'organizations';

    public const ORGANIZATION_DETAIL = 'organization-detail';

    public const APPROVAL_WORKFLOW = 'approval-workflow';

    public const APPROVAL_PAYMENTS = 'approval-payments';

    public const APPROVAL_DOCUMENTS = 'approval-documents';

    public const APPROVAL_FINAL_VERIFICATION = 'approval-final-verification';

    public const APPROVAL_INFO_REQUESTS = 'approval-info-requests';

    public const APPROVAL_REJECTED_HISTORY = 'approval-rejected-history';

    public const COMPLIANCE_DOCUMENTS = 'compliance-documents';

    public const FEES = 'fees';

    public const REPORTS = 'reports';

    public const ZONE_REFERRALS = 'zone-referrals';

    public const ZONE_MOMENTUM = 'zone-momentum';

    public const GROWTH_PAYMENTS = 'growth-payments';

    public const PURCHASE_ORDERS = 'purchase-orders';

    public const PURCHASE_ORDER_DETAIL = 'purchase-order-detail';

    public const INVENTORY = 'inventory';

    public const INVENTORY_ORDERS = 'inventory-orders';

    public const INVENTORY_ORDER_DETAILS = 'inventory-order-details';

    public const CATEGORIES_LOCATIONS = 'categories-locations';

    public const MINISTRIES = 'ministries';

    public const GBPC_REGISTRATIONS = 'gbpc-registrations';

    public const ADS = 'ads';

    public const AD_INQUIRIES = 'ad-inquiries';

    public const SUBSCRIBERS = 'subscribers';

    public const MAIL_TEST = 'mail-test';

    public const ADMINS = 'admins';

    public const ACTIVITY = 'activity';

    public const ASSISTANT = 'assistant';

    public const ASSISTANT_LIVE = 'assistant-live';

    public const ASSISTANT_BLUEPRINTS = 'assistant-blueprints';

    public const MESSAGES = 'messages';

    public const SUPPORT = 'support';

    public const SUPPORT_FEEDBACK = 'support-feedback';

    public const NETWORK_REPORTS = 'network-reports';

    /**
     * Industry group chats. Members are placed in these automatically on
     * verification, so the rooms need an oversight surface of their own —
     * NETWORK_REPORTS only covers messages somebody has reported.
     */
    public const NETWORK_GROUPS = 'network-groups';

    /**
     * @return array<string, array{
     *     label: string,
     *     group: string,
     *     route?: string|null,
     *     routes: array<int, string>,
     *     dataPage?: string,
     *     legacy: array<int, string>
     * }>
     */
    public static function all(): array
    {
        return [
            self::DASHBOARD => [
                'label' => 'Admin Dashboard',
                'group' => 'Overview',
                'route' => 'admin.dashboard',
                'routes' => ['admin.dashboard'],
                'dataPage' => 'dashboard',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
            self::USERS => [
                'label' => 'Users',
                'group' => 'Directory',
                'route' => 'admin.users.index',
                'routes' => ['admin.users.index'],
                'dataPage' => 'users',
                'legacy' => [AdminCapabilities::MANAGE_USERS],
            ],
            self::BUSINESSES => [
                'label' => 'SBO Directory',
                'group' => 'Directory',
                'route' => 'admin.businesses.index',
                'routes' => ['admin.businesses.index', 'admin.businesses.export'],
                'dataPage' => 'businesses',
                'legacy' => [
                    AdminCapabilities::MANAGE_BUSINESSES,
                    AdminCapabilities::MODERATE_REVIEWS,
                    AdminCapabilities::APPROVE_SBOS,
                ],
            ],
            self::BUSINESS_DETAIL => [
                'label' => 'SBO Directory Details',
                'group' => 'Directory',
                'route' => null,
                'routes' => ['admin.businesses.show'],
                'dataPage' => 'business-detail',
                'legacy' => [
                    AdminCapabilities::MANAGE_BUSINESSES,
                    AdminCapabilities::MODERATE_REVIEWS,
                    AdminCapabilities::APPROVE_SBOS,
                ],
            ],
            self::COB_BUSINESSES => [
                'label' => 'COB Directory',
                'group' => 'Directory',
                'route' => 'admin.cob.index',
                'routes' => ['admin.cob.index', 'admin.businesses.export'],
                'dataPage' => 'cob-businesses',
                'legacy' => [
                    AdminCapabilities::MANAGE_BUSINESSES,
                    AdminCapabilities::MODERATE_REVIEWS,
                    AdminCapabilities::APPROVE_SBOS,
                ],
            ],
            self::ORGANIZATIONS => [
                'label' => 'Application Approvals',
                'group' => 'SBO Review',
                'route' => 'admin.sbos.index',
                'routes' => ['admin.sbos.index', 'admin.sbos.export'],
                'dataPage' => 'sbos',
                'legacy' => [
                    AdminCapabilities::VIEW_ADMIN,
                    AdminCapabilities::APPROVE_SBOS,
                    AdminCapabilities::ASSIGN_SBO_MANAGERS,
                ],
            ],
            self::ORGANIZATION_DETAIL => [
                'label' => 'Organization Detail & Review',
                'group' => 'SBO Review',
                'route' => null,
                'routes' => ['admin.sbos.show'],
                'dataPage' => 'sbo-detail',
                'legacy' => [
                    AdminCapabilities::VIEW_ADMIN,
                    AdminCapabilities::APPROVE_SBOS,
                    AdminCapabilities::ASSIGN_SBO_MANAGERS,
                ],
            ],
            self::APPROVAL_WORKFLOW => [
                'label' => 'Validation Approvals',
                'group' => 'SBO Review',
                'route' => 'admin.approvals.index',
                'routes' => [
                    'admin.approvals.index',
                    'admin.approvals.payments',
                    'admin.approvals.documents',
                    'admin.approvals.final-verification',
                ],
                'dataPage' => 'approvals-workflow',
                'legacy' => [AdminCapabilities::APPROVE_SBOS],
            ],
            self::APPROVAL_INFO_REQUESTS => [
                'label' => 'Info Requests',
                'group' => 'SBO Review',
                'route' => 'admin.approvals.info-requests',
                'routes' => ['admin.approvals.info-requests'],
                'dataPage' => 'approvals-info-requests',
                'legacy' => [AdminCapabilities::APPROVE_SBOS],
            ],
            self::APPROVAL_REJECTED_HISTORY => [
                'label' => 'Rejected History',
                'group' => 'SBO Review',
                'route' => 'admin.approvals.rejected-history',
                'routes' => ['admin.approvals.rejected-history'],
                'dataPage' => 'approvals-rejected-history',
                'legacy' => [AdminCapabilities::APPROVE_SBOS],
            ],
            self::COMPLIANCE_DOCUMENTS => [
                'label' => 'Required Documents',
                'group' => 'SBO Workflow',
                'route' => 'admin.compliance.documents.index',
                'routes' => ['admin.compliance.documents.index'],
                'dataPage' => 'compliance-documents',
                'legacy' => [AdminCapabilities::APPROVE_SBOS],
            ],
            self::FEES => [
                'label' => 'Registration Fees & Espees',
                'group' => 'SBO Workflow',
                'route' => 'admin.fees.index',
                'routes' => ['admin.fees.index'],
                'dataPage' => 'fees',
                'legacy' => [AdminCapabilities::APPROVE_SBOS],
            ],
            self::REPORTS => [
                'label' => 'Reports',
                'group' => 'SBO Workflow',
                'route' => 'admin.reports.index',
                'routes' => ['admin.reports.index'],
                'dataPage' => 'reports',
                'legacy' => [AdminCapabilities::MANAGE_REPORTS],
            ],
            self::ZONE_REFERRALS => [
                'label' => 'Zone Referrals',
                'group' => 'Growth',
                'route' => 'admin.zones.referrals.index',
                'routes' => ['admin.zones.referrals.index'],
                'dataPage' => 'zone-referrals',
                'legacy' => [AdminCapabilities::MANAGE_ZONE_REFERRALS],
            ],
            self::ZONE_MOMENTUM => [
                'label' => 'Zone Momentum',
                'group' => 'Growth',
                'route' => 'admin.zones.momentum.index',
                'routes' => ['admin.zones.momentum.index'],
                'dataPage' => 'zone-momentum',
                'legacy' => [AdminCapabilities::MANAGE_ZONE_REFERRALS],
            ],
            self::GROWTH_PAYMENTS => [
                'label' => 'Payments',
                'group' => 'Growth',
                'route' => 'admin.growth.payments',
                'routes' => ['admin.growth.payments'],
                'dataPage' => 'growth-payments',
                'legacy' => [AdminCapabilities::VIEW_SBO_PAYMENTS],
            ],
            self::PURCHASE_ORDERS => [
                'label' => 'Procurement Requests',
                'group' => 'Procurement',
                'route' => 'admin.purchase-orders.index',
                'routes' => ['admin.purchase-orders.index'],
                'dataPage' => 'purchase-orders',
                'legacy' => [AdminCapabilities::MANAGE_RFQS, AdminCapabilities::AWARD_BIDS],
            ],
            self::PURCHASE_ORDER_DETAIL => [
                'label' => 'Procurement Request Details',
                'group' => 'Procurement',
                'route' => null,
                'routes' => ['admin.purchase-orders.show'],
                'dataPage' => 'purchase-order-detail',
                'legacy' => [AdminCapabilities::MANAGE_RFQS, AdminCapabilities::AWARD_BIDS],
            ],
            self::INVENTORY => [
                'label' => 'Inventory',
                'group' => 'Store',
                'route' => 'admin.inventory.index',
                'routes' => ['admin.inventory.index'],
                'dataPage' => 'inventory',
                // Super-admins get this automatically; grantable to other admins.
                'legacy' => [],
            ],
            self::INVENTORY_ORDERS => [
                'label' => 'Inventory Orders',
                'group' => 'Store',
                'route' => 'admin.inventory.orders.index',
                'routes' => ['admin.inventory.orders.index'],
                'dataPage' => 'inventory-orders',
                'legacy' => [],
            ],
            self::INVENTORY_ORDER_DETAILS => [
                'label' => 'Order Details',
                'group' => 'Store',
                'route' => 'admin.inventory.order-details.index',
                'routes' => ['admin.inventory.order-details.index'],
                'dataPage' => 'inventory-order-details',
                'legacy' => [],
            ],
            self::CATEGORIES_LOCATIONS => [
                'label' => 'Categories & Locations',
                'group' => 'Configuration',
                'route' => 'admin.categories-locations.index',
                'routes' => ['admin.categories-locations.index'],
                'dataPage' => 'categories-locations',
                'legacy' => [AdminCapabilities::MANAGE_CATEGORIES],
            ],
            self::MINISTRIES => [
                'label' => 'Ministries',
                'group' => 'Configuration',
                'route' => 'admin.ministries.index',
                'routes' => ['admin.ministries.index'],
                'dataPage' => 'ministries',
                'legacy' => [AdminCapabilities::MANAGE_CATEGORIES],
            ],
            self::GBPC_REGISTRATIONS => [
                'label' => 'GBPC Registrations',
                'group' => 'Configuration',
                'route' => 'admin.gbpc-registrations.index',
                'routes' => ['admin.gbpc-registrations.index', 'admin.gbpc-registrations.export'],
                'dataPage' => 'gbpc-registrations',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
            self::ADS => [
                'label' => 'Ad Placements',
                'group' => 'Configuration',
                'route' => 'admin.ads.index',
                'routes' => ['admin.ads.index'],
                'dataPage' => 'ads',
                'legacy' => [AdminCapabilities::MANAGE_ADS],
            ],
            self::AD_INQUIRIES => [
                'label' => 'Advertising Inquiries',
                'group' => 'Configuration',
                'route' => 'admin.ads.inquiries',
                'routes' => ['admin.ads.inquiries'],
                'dataPage' => 'ads-inquiries',
                // Same capability as the placements themselves: whoever decides
                // what a slot is decides who gets to buy it.
                'legacy' => [AdminCapabilities::MANAGE_ADS],
            ],
            self::SUBSCRIBERS => [
                'label' => 'Subscribers',
                'group' => 'Configuration',
                'route' => 'admin.subscribers.index',
                'routes' => ['admin.subscribers.index'],
                'dataPage' => 'subscribers',
                // Same capability as the ad placements: both are outbound
                // marketing surfaces, and whoever runs one runs the other.
                'legacy' => [AdminCapabilities::MANAGE_ADS],
            ],
            self::MAIL_TEST => [
                'label' => 'Mail Test',
                'group' => 'Configuration',
                'route' => 'admin.mail-test',
                'routes' => ['admin.mail-test'],
                'dataPage' => 'mail-test',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
            self::ADMINS => [
                'label' => 'Administrators',
                'group' => 'Configuration',
                'route' => 'admin.admins.index',
                'routes' => ['admin.admins.index'],
                'dataPage' => 'admins',
                'legacy' => [AdminCapabilities::MANAGE_ADMINS],
            ],
            self::ACTIVITY => [
                'label' => 'Activity Log',
                'group' => 'Oversight',
                'route' => 'admin.activity.index',
                'routes' => ['admin.activity.index'],
                'dataPage' => 'activity',
                'legacy' => [AdminCapabilities::VIEW_AUDIT_LOG],
            ],
            self::ASSISTANT => [
                'label' => 'Sales Assistant',
                'group' => 'Support',
                'route' => 'admin.assistant.index',
                'routes' => ['admin.assistant.index'],
                'dataPage' => 'assistant',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
            self::ASSISTANT_LIVE => [
                'label' => 'Live Chat',
                'group' => 'Support',
                'route' => 'admin.assistant.live',
                /*
                 * The console's polling and action endpoints belong to this
                 * page. admin_page gates by route name, so every route the page
                 * calls has to be listed or the page loads and then cannot
                 * fetch its conversations or reply.
                 */
                'routes' => [
                    'admin.assistant.live',
                    'admin.assistant.live.ping',
                    'admin.assistant.live.sessions',
                    'admin.assistant.live.thread',
                    'admin.assistant.live.takeover',
                    'admin.assistant.live.send',
                    'admin.assistant.live.end',
                ],
                'dataPage' => 'assistant-live',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
            self::ASSISTANT_BLUEPRINTS => [
                'label' => 'Assistant Blueprints',
                'group' => 'Support',
                'route' => 'admin.chatbot.blueprints',
                /*
                 * The canvas's own two endpoints belong to this page. The
                 * admin_page middleware gates by route name, so leaving them
                 * off means the page renders and then cannot load or save the
                 * graph it is showing.
                 */
                'routes' => [
                    'admin.chatbot.blueprints',
                    'admin.chatbot.blueprints.show',
                    'admin.chatbot.blueprints.save',
                ],
                'dataPage' => 'chatbot-blueprints',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
            self::MESSAGES => [
                'label' => 'Member Messages',
                'group' => 'Support',
                'route' => 'admin.messages.index',
                'routes' => ['admin.messages.index'],
                'dataPage' => 'messages',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
            self::SUPPORT => [
                'label' => 'Support Enquiries',
                'group' => 'Support',
                'route' => 'admin.support.index',
                'routes' => ['admin.support.index'],
                'dataPage' => 'support',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
            self::SUPPORT_FEEDBACK => [
                'label' => 'Feedback',
                'group' => 'Support',
                'route' => 'admin.support.feedback',
                'routes' => ['admin.support.feedback'],
                'dataPage' => 'support-feedback',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
            self::NETWORK_REPORTS => [
                'label' => 'Network Reports',
                'group' => 'Support',
                'route' => 'admin.network.reports',
                'routes' => ['admin.network.reports'],
                'dataPage' => 'network-reports',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
            self::NETWORK_GROUPS => [
                'label' => 'Category Groups',
                'group' => 'Support',
                'route' => 'admin.network.groups',
                'routes' => ['admin.network.groups'],
                'dataPage' => 'network-groups',
                'legacy' => [AdminCapabilities::VIEW_ADMIN],
            ],
        ];
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::all() as $key => $page) {
            $grouped[$page['group']][$key] = $page;
        }

        return $grouped;
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            self::APPROVAL_PAYMENTS => self::APPROVAL_WORKFLOW,
            self::APPROVAL_DOCUMENTS => self::APPROVAL_WORKFLOW,
            self::APPROVAL_FINAL_VERIFICATION => self::APPROVAL_WORKFLOW,
        ];
    }

    /**
     * @param  array<int, string>|null  $keys
     * @return array<int, string>
     */
    public static function normalize(?array $keys): array
    {
        $allowed = self::keys();
        $aliases = self::aliases();

        $keys = array_map(
            fn (string $key): string => $aliases[$key] ?? $key,
            $keys ?? []
        );

        return array_values(array_unique(array_intersect($keys, $allowed)));
    }

    public static function keyForRoute(?string $routeName): ?string
    {
        if ( ! $routeName) {
            return null;
        }

        foreach (self::all() as $key => $page) {
            if (in_array($routeName, $page['routes'], true)) {
                return $key;
            }
        }

        return null;
    }

    public static function routeNameFor(string $key): ?string
    {
        $page = self::all()[$key] ?? null;

        return $page['route'] ?? null;
    }

    public static function labelFor(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    /**
     * Pages every existing ordinary admin could already reach before the
     * page-level gate was introduced.
     *
     * @return array<int, string>
     */
    public static function existingAdminDefaults(): array
    {
        return [
            self::ORGANIZATIONS,
            self::ORGANIZATION_DETAIL,
            self::ZONE_REFERRALS,
            self::ZONE_MOMENTUM,
            self::SUPPORT,
            self::SUPPORT_FEEDBACK,
            self::NETWORK_REPORTS,
            self::NETWORK_GROUPS,
        ];
    }

    /**
     * @param  array<int, string>|null  $capabilities
     * @return array<int, string>
     */
    public static function fromLegacyCapabilities(?array $capabilities, bool $includeDefaults = false): array
    {
        $capabilities = array_values(array_unique($capabilities ?? []));
        $pages = $includeDefaults ? self::existingAdminDefaults() : [];

        foreach (self::all() as $key => $page) {
            if (array_intersect($capabilities, $page['legacy'])) {
                $pages[] = $key;
            }
        }

        return self::normalize($pages);
    }

    /**
     * @param  array<int, string>|null  $pageKeys
     * @return array<int, string>
     */
    public static function capabilitiesForPages(?array $pageKeys): array
    {
        $capabilities = [];

        foreach (self::normalize($pageKeys) as $key) {
            $capabilities = [...$capabilities, ...self::all()[$key]['legacy']];
        }

        return array_values(array_unique(array_intersect($capabilities, AdminCapabilities::all())));
    }

    public static function capabilityIsCoveredByPages(User $user, string $capability): bool
    {
        $attributes = $user->getAttributes();
        $pagePermissions = array_key_exists('admin_page_permissions', $attributes)
            ? $user->admin_page_permissions
            : null;

        if (null === $pagePermissions) {
            $legacyPermissions = array_key_exists('permissions', $attributes)
                ? ($user->permissions ?? [])
                : [];

            $pagePermissions = self::fromLegacyCapabilities($legacyPermissions, includeDefaults: true);
        }

        foreach (self::normalize($pagePermissions) as $key) {
            if (in_array($capability, self::all()[$key]['legacy'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{label: string, pages: array<int, string>}>
     */
    public static function presets(): array
    {
        return [
            'review' => [
                'label' => 'SBO Review Admin',
                'pages' => [
                    self::ORGANIZATIONS,
                    self::ORGANIZATION_DETAIL,
                    self::APPROVAL_WORKFLOW,
                    self::APPROVAL_INFO_REQUESTS,
                    self::APPROVAL_REJECTED_HISTORY,
                    self::COMPLIANCE_DOCUMENTS,
                    self::FEES,
                ],
            ],
            'directory' => [
                'label' => 'Directory Admin',
                'pages' => [
                    self::USERS,
                    self::BUSINESSES,
                    self::BUSINESS_DETAIL,
                    self::COB_BUSINESSES,
                    self::CATEGORIES_LOCATIONS,
                    self::MINISTRIES,
                    self::GBPC_REGISTRATIONS,
                ],
            ],
            'growth' => [
                'label' => 'Growth Admin',
                'pages' => [
                    self::ZONE_REFERRALS,
                    self::ZONE_MOMENTUM,
                    self::GROWTH_PAYMENTS,
                    self::ADS,
                ],
            ],
            'procurement' => [
                'label' => 'Procurement Admin',
                'pages' => [
                    self::PURCHASE_ORDERS,
                    self::PURCHASE_ORDER_DETAIL,
                ],
            ],
            'support' => [
                'label' => 'Support Admin',
                'pages' => [
                    self::SUPPORT,
                    self::SUPPORT_FEEDBACK,
                    self::NETWORK_REPORTS,
                    self::NETWORK_GROUPS,
                ],
            ],
            'oversight' => [
                'label' => 'Oversight Admin',
                'pages' => [
                    self::DASHBOARD,
                    self::REPORTS,
                    self::ACTIVITY,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>|null  $pageKeys
     */
    public static function typeLabel(?array $pageKeys): string
    {
        $pageKeys = self::normalize($pageKeys);
        sort($pageKeys);

        foreach (self::presets() as $preset) {
            $presetPages = self::normalize($preset['pages']);
            sort($presetPages);

            if ($pageKeys === $presetPages) {
                return $preset['label'];
            }
        }

        return [] === $pageKeys ? 'No page access' : 'Custom page access';
    }

    public static function firstAccessibleRouteName(User $user): ?string
    {
        if ($user->isSuperAdmin()) {
            return 'admin.dashboard';
        }

        $attributes = $user->getAttributes();
        $pagePermissions = array_key_exists('admin_page_permissions', $attributes)
            ? $user->admin_page_permissions
            : null;

        if (null === $pagePermissions) {
            $legacyPermissions = array_key_exists('permissions', $attributes)
                ? ($user->permissions ?? [])
                : [];

            $pagePermissions = self::fromLegacyCapabilities($legacyPermissions, includeDefaults: true);
        }

        foreach (self::normalize($pagePermissions) as $key) {
            $routeName = self::routeNameFor($key);

            if ($routeName) {
                return $routeName;
            }
        }

        return null;
    }
}
