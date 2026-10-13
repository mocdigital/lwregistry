<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use App\Models\User;

/**
 * The Account Completion checklist on /settings/profile.
 *
 * It was a fixed array: 75%, a three-quarter progress bar, and four steps that
 * always read the same — including "Register corporate entity (Kenafly)", a
 * company name belonging to nobody using the system. A member who had verified
 * nothing saw the same 75% as one who had completed everything, so the panel
 * could not tell anyone what to do next, which is its only job.
 *
 * Each step is a real condition, and the percentage is simply how many of them
 * are met.
 */
final class AccountCompletion
{
    /**
     * @return array{percent: int, done: int, total: int, headline: string, steps: list<array{key: string, label: string, done: bool, href: string|null}>}
     */
    public static function for(User $user): array
    {
        $sbo = $user->sbos()->latest('id')->first();

        $steps = [
            self::emailVerified($user),
            self::profileDetails($user),
            self::corporateEntity($sbo),
            self::complianceDocuments($sbo),
        ];

        $done = count(array_filter($steps, fn (array $s): bool => $s['done']));
        $total = count($steps);
        $percent = 0 === $total ? 0 : (int) round($done / $total * 100);

        return [
            'percent' => $percent,
            'done' => $done,
            'total' => $total,
            'headline' => self::headline($percent),
            'steps' => $steps,
        ];
    }

    /**
     * @return array{key: string, label: string, done: bool, href: string|null}
     */
    private static function emailVerified(User $user): array
    {
        return [
            'key' => 'email',
            'label' => __('completion.email'),
            'done' => $user->hasVerifiedEmail(),
            'href' => $user->hasVerifiedEmail() ? null : route('verification.notice'),
        ];
    }

    /**
     * Basic details: a name to show and a way to reach them.
     *
     * @return array{key: string, label: string, done: bool, href: string|null}
     */
    private static function profileDetails(User $user): array
    {
        $done = filled($user->firstname) && filled($user->lastname) && filled($user->phone);

        return [
            'key' => 'profile',
            'label' => __('completion.profile'),
            'done' => $done,
            /*
             * `profile.edit`, not `settings.profile`. The GET route for
             * /settings/profile is named `profile.edit`; `settings.profile`
             * does not exist, and only `settings.profile.update` (the PATCH)
             * looks like it does.
             *
             * This threw a RouteNotFoundException — a 500 on the settings page
             * itself — for exactly the members this checklist is FOR: the ones
             * whose profile is incomplete. A complete profile made `$done`
             * true, skipped the route() call, and hid the bug.
             */
            'href' => $done ? null : route('profile.edit'),
        ];
    }

    /**
     * @return array{key: string, label: string, done: bool, href: string|null}
     */
    private static function corporateEntity(?Sbo $sbo): array
    {
        /*
         * The label names the member's OWN business. The hardcoded version
         * said "(Kenafly)" to everybody — a company nobody using the system
         * belongs to.
         */
        return [
            'key' => 'entity',
            'label' => null === $sbo
                ? __('completion.entity')
                : __('completion.entity_named', ['name' => $sbo->name]),
            'done' => null !== $sbo,
            'href' => null === $sbo ? route('sbo.listings.create') : null,
        ];
    }

    /**
     * Compliance documents, which is what the design called "tax validation".
     *
     * Counted as done only when the registry has marked them compliant —
     * uploading is not the same as being accepted, and the badge this checklist
     * unlocks depends on the acceptance.
     *
     * @return array{key: string, label: string, done: bool, href: string|null}
     */
    private static function complianceDocuments(?Sbo $sbo): array
    {
        return [
            'key' => 'documents',
            'label' => __('completion.documents'),
            'done' => null !== $sbo && $sbo->documentsCompliant(),
            'href' => null === $sbo ? null : route('sbo.applications.progress', $sbo),
        ];
    }

    private static function headline(int $percent): string
    {
        return match (true) {
            100 === $percent => __('completion.all_done'),
            $percent >= 75 => __('completion.excellent'),
            $percent >= 50 => __('completion.halfway'),
            $percent > 0 => __('completion.good_start'),
            default => __('completion.get_started'),
        };
    }
}
