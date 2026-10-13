<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The Security Overview panel, from real columns.
 *
 * It used to be three hard-coded strings, the same for everybody: "Two-Factor
 * Auth: ACTIVE", "Password Age: 42 days (Good)", "Last Login Device: Chrome,
 * Nairobi". The first was actively dangerous — it told every member their
 * account was protected by a second factor that did not exist in the codebase
 * at all, which is the kind of reassurance that stops someone enabling the
 * real thing.
 *
 * Every row now says what is true, including when the honest answer is that
 * nothing has been recorded yet.
 */
final class SecurityOverview
{
    /**
     * @return list<array{label: string, value: string, tone: 'good'|'warn'|'bad'|'neutral'}>
     */
    public static function for(User $user): array
    {
        return [
            self::twoFactor($user),
            self::passwordAge($user),
            self::lastLoginDevice($user),
        ];
    }

    /**
     * @return array{label: string, value: string, tone: 'good'|'warn'|'bad'|'neutral'}
     */
    private static function twoFactor(User $user): array
    {
        return [
            'label' => __('security.two_factor'),
            'value' => $user->hasTwoFactorEnabled() ? __('security.active') : __('security.not_set_up'),
            // "Off" is a real weakness, so it reads as one rather than as a
            // neutral fact.
            'tone' => $user->hasTwoFactorEnabled() ? 'good' : 'bad',
        ];
    }

    /**
     * @return array{label: string, value: string, tone: 'good'|'warn'|'bad'|'neutral'}
     */
    private static function passwordAge(User $user): array
    {
        $days = $user->passwordAgeInDays();

        if (null === $days) {
            return ['label' => __('security.password_age'), 'value' => __('security.not_recorded'), 'tone' => 'neutral'];
        }

        // Thresholds are advisory: nothing expires a password, the panel just
        // stops calling a two-year-old one "Good".
        [$suffix, $tone] = match (true) {
            $days >= 365 => [__('security.change_it'), 'bad'],
            $days >= 180 => [__('security.ageing'), 'warn'],
            default => [__('security.good'), 'good'],
        };

        $label = 0 === $days
            ? __('security.today')
            : trans_choice('security.day_count', $days, ['count' => $days]);

        return [
            'label' => __('security.password_age'),
            'value' => $label . ' (' . $suffix . ')',
            'tone' => $tone,
        ];
    }

    /**
     * @return array{label: string, value: string, tone: 'good'|'warn'|'bad'|'neutral'}
     */
    private static function lastLoginDevice(User $user): array
    {
        // Same tolerance as User::securityAttribute — these columns are newer
        // than much of the code that builds User instances.
        $attrs = $user->getAttributes();
        $lastLoginAt = array_key_exists('last_login_at', $attrs) ? $user->last_login_at : null;
        $agent = (string) ($attrs['last_login_user_agent'] ?? '');

        if (null === $lastLoginAt) {
            return ['label' => __('security.last_login'), 'value' => __('security.no_sign_in'), 'tone' => 'neutral'];
        }

        $browser = self::browserFrom($agent);
        $when = $lastLoginAt->diffForHumans();

        /*
         * The old string claimed a city ("Chrome, Nairobi"). Deriving one needs
         * a geo-IP service the app does not have, and guessing a member's
         * location wrongly is worse than not showing it — so this reports the
         * browser and when, which are both actually known.
         */
        return [
            'label' => __('security.last_login'),
            'value' => $browser . ' · ' . $when,
            'tone' => 'neutral',
        ];
    }

    /**
     * A readable browser name from a user-agent string.
     *
     * Order matters: Edge and Opera both carry "Chrome" in their UA, and
     * Chrome carries "Safari", so the more specific tokens are tested first.
     */
    private static function browserFrom(string $agent): string
    {
        if ('' === mb_trim($agent)) {
            return __('security.unknown_device');
        }

        foreach ([
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'Firefox' => 'Firefox',
            'Chrome' => 'Chrome',
            'Safari' => 'Safari',
        ] as $token => $name) {
            if (str_contains($agent, $token)) {
                return $name;
            }
        }

        return __('security.unknown_device');
    }
}
