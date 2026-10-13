<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Arr;

/**
 * The Appearance and Notifications panels of /settings.
 *
 * Both were drawn in full and stored nothing: theme, accent, font size,
 * density, rail side and every notification switch were client-side `ref`s, so
 * a member set their preferences and found them reset on the next page load.
 *
 * The defaults here are the states the frames draw, so a member who has never
 * opened the screen sees exactly what the design shows. Values are validated
 * against these keys on save — an unknown key is dropped rather than stored,
 * which keeps the column from accumulating whatever a client chooses to post.
 */
final class UserPreferences
{
    /** @var array<int, string> */
    public const THEMES = ['light', 'dark', 'system'];

    /**
     * ColorSwatchesGroup 223:5117 — the Figma nodes are named by their own hex,
     * so the stored value is the hex rather than a name invented here.
     *
     * @var array<int, string>
     */
    public const ACCENTS = [
        '#0d9488', '#3b82f6', '#8b5cf6', '#6366f1', '#f97316', '#f59e0b', '#ec4899', '#10b981',
    ];

    /** @var array<int, string> */
    public const FONT_SIZES = ['Small', 'Medium', 'Large'];

    /** @var array<int, string> */
    public const DENSITIES = ['comfortable', 'compact'];

    /** @var array<int, string> */
    public const RAIL_SIDES = ['left', 'right'];

    /**
     * Every switch on the appearance panel, with the state its frame draws.
     *
     * @var array<string, bool>
     */
    public const APPEARANCE_TOGGLES = [
        'autoCollapseSidebar' => true,
        'reduceMotion' => false,
        'highContrast' => false,
        'screenReaderOptimizations' => true,
    ];

    /**
     * Every notification switch, across the notifications panel (email 223:5389
     * and push) and the digest block on the profile panel.
     *
     * @var array<string, bool>
     */
    public const NOTIFICATION_TOGGLES = [
        // Email
        'applicationUpdates' => true,
        'paymentConfirmations' => true,
        'networkActivity' => true,
        'marketingResearch' => false,
        // Push
        'applicationMilestones' => true,
        'realTimeAlerts' => true,
        'chatMessages' => false,
        // Digest, drawn on the profile panel
        'dailyOpportunityMatches' => true,
        'bidRequestUpdates' => true,
        'weeklyNewsletter' => false,
    ];

    /**
     * Regional settings.
     *
     * The Timezone and Language fields on /settings/profile were text boxes
     * with "East Africa Time (GMT+3)" and "English (UK)" typed into them,
     * saving nothing — so a member in Lagos read every deadline and message
     * time in UTC while being told it was Nairobi.
     *
     * Only the timezone lives here. Language is `users.locale`, which the
     * header selector already writes; a second copy of it would be two answers
     * to one question.
     *
     * @var array<string, string|null>
     */
    public const REGIONAL = [
        // Null means "the app's own zone", not a guess at the member's.
        'timezone' => null,
    ];

    /**
     * The Privacy panel.
     *
     * The rail listed "Privacy" and it went nowhere — a label with no page
     * behind it, which reads as a broken link rather than a feature nobody got
     * to. These are the four decisions a member can actually make about how
     * visible they are, each one read by the code that renders the thing it
     * governs.
     *
     * Defaults are the open ones. A business joins a REGISTRY to be found; a
     * member who wants less than that opts out deliberately.
     *
     * @var array<string, bool>
     */
    public const PRIVACY_TOGGLES = [
        // Whether the member's listings appear in the public directory and in
        // search. Off leaves the page reachable by direct link, so an existing
        // link a customer holds does not break.
        'listedInDirectory' => true,
        // Whether the public profile prints the contact email and phone.
        'showContactDetails' => true,
        // Whether other members may send a connection request at all.
        'acceptConnectionRequests' => true,
        // Whether a profile view is recorded against the viewer's account. Off
        // still counts the view — the owner's analytics are theirs — but drops
        // who it was.
        'shareViewIdentity' => true,
    ];

    /**
     * What the design draws before anybody changes anything.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'appearance' => [
                'theme' => 'light',
                'accent' => self::ACCENTS[0],
                'fontSize' => 'Medium',
                'density' => 'comfortable',
                /*
                 * 'right', not 'left'. The frames label Left as the default,
                 * but every screen that has been built puts the rail on the
                 * right — so defaulting to left would flip the layout for
                 * every existing member on deploy, which is not a default.
                 */
                'railSide' => 'right',
                'toggles' => self::APPEARANCE_TOGGLES,
            ],
            'notifications' => self::NOTIFICATION_TOGGLES,
            'privacy' => self::PRIVACY_TOGGLES,
            'regional' => self::REGIONAL,
        ];
    }

    /**
     * The member's stored preferences over the defaults, so a panel added after
     * they last saved still arrives with its designed state rather than null.
     *
     * @return array<string, mixed>
     */
    public static function for(User $user): array
    {
        $stored = self::stored($user);
        $defaults = self::defaults();

        return [
            'appearance' => [
                ...$defaults['appearance'],
                ...Arr::only(
                    (array) ($stored['appearance'] ?? []),
                    ['theme', 'accent', 'fontSize', 'density', 'railSide'],
                ),
                'toggles' => [
                    ...$defaults['appearance']['toggles'],
                    ...Arr::only(
                        (array) ($stored['appearance']['toggles'] ?? []),
                        array_keys(self::APPEARANCE_TOGGLES),
                    ),
                ],
            ],
            'notifications' => [
                ...$defaults['notifications'],
                ...Arr::only((array) ($stored['notifications'] ?? []), array_keys(self::NOTIFICATION_TOGGLES)),
            ],
            'privacy' => [
                ...$defaults['privacy'],
                ...Arr::only((array) ($stored['privacy'] ?? []), array_keys(self::PRIVACY_TOGGLES)),
            ],
            'regional' => [
                ...$defaults['regional'],
                ...Arr::only((array) ($stored['regional'] ?? []), array_keys(self::REGIONAL)),
            ],
        ];
    }

    /**
     * Validation rules for a preferences save. Each field is optional so a
     * panel can save its own half without resending the other.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [
            'appearance' => ['sometimes', 'array'],
            'appearance.theme' => ['sometimes', 'string', 'in:' . implode(',', self::THEMES)],
            'appearance.accent' => ['sometimes', 'string', 'in:' . implode(',', self::ACCENTS)],
            'appearance.fontSize' => ['sometimes', 'string', 'in:' . implode(',', self::FONT_SIZES)],
            'appearance.density' => ['sometimes', 'string', 'in:' . implode(',', self::DENSITIES)],
            'appearance.railSide' => ['sometimes', 'string', 'in:' . implode(',', self::RAIL_SIDES)],
            'appearance.toggles' => ['sometimes', 'array'],
            'notifications' => ['sometimes', 'array'],
            'privacy' => ['sometimes', 'array'],
            'regional' => ['sometimes', 'array'],
            // Any IANA identifier, not only the ones the select offers — a
            // value saved before the list changed must not become unsavable.
            'regional.timezone' => ['sometimes', 'nullable', 'timezone'],
        ];

        foreach (array_keys(self::APPEARANCE_TOGGLES) as $key) {
            $rules['appearance.toggles.' . $key] = ['sometimes', 'boolean'];
        }

        foreach (array_keys(self::NOTIFICATION_TOGGLES) as $key) {
            $rules['notifications.' . $key] = ['sometimes', 'boolean'];
        }

        foreach (array_keys(self::PRIVACY_TOGGLES) as $key) {
            $rules['privacy.' . $key] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * Merge a validated patch into what is already stored, and persist it.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public static function merge(User $user, array $patch): array
    {
        $current = self::for($user);

        $next = [
            'appearance' => [
                ...$current['appearance'],
                ...Arr::except((array) ($patch['appearance'] ?? []), ['toggles']),
                'toggles' => [
                    ...$current['appearance']['toggles'],
                    ...(array) ($patch['appearance']['toggles'] ?? []),
                ],
            ],
            'notifications' => [
                ...$current['notifications'],
                ...(array) ($patch['notifications'] ?? []),
            ],
            'privacy' => [
                ...$current['privacy'],
                ...(array) ($patch['privacy'] ?? []),
            ],
            'regional' => [
                ...$current['regional'],
                ...(array) ($patch['regional'] ?? []),
            ],
        ];

        $user->preferences = $next;
        $user->save();

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    private static function stored(User $user): array
    {
        // `preventAccessingMissingAttributes` is on, so a query that did not
        // select the column must not look like an empty preference set.
        if ( ! array_key_exists('preferences', $user->getAttributes())) {
            return [];
        }

        return (array) ($user->preferences ?? []);
    }
}
