<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\TurnstileVerifier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * `/advertise`.
 *
 * The route was `fn () => Inertia::render('public/AdvertiseView')` — no props
 * at all — so the screen hardcoded nine placements and their pixel sizes, and
 * its inquiry form had nothing to post. The Blade version it replaced read
 * `config/ads.php`, prefilled the signed-in advertiser and rendered Turnstile.
 *
 * `config/ads.php` matters here specifically: `AdvertisingInquiryController`
 * validates `ad_location` against `array_keys(config('ads.slots'))`, so a
 * screen offering any other set of options is offering choices the endpoint
 * will reject.
 */
final class AdvertiseController extends Controller
{
    public function show(Request $request): InertiaResponse
    {
        $user = $request->user();

        // The daily rate per placement, read from the admin's rate cards — the
        // price a campaign is billed at. Derived from the card as price ÷ days,
        // taking the most granular active card for the slot, so whatever the
        // admin sets there is what the builder quotes. The config `rate` is only
        // a fallback for a slot no card covers yet.
        $dailyRates = \App\Models\AdRateCard::query()
            ->where('is_active', true)
            ->orderBy('duration_days')
            ->get()
            ->groupBy('slot')
            ->map(function ($cards): int {
                $card = $cards->first();

                return $card->duration_days > 0
                    ? (int) round($card->price / $card->duration_days)
                    : (int) $card->price;
            });

        return Inertia::render('public/AdvertiseView', [
            /*
             * Every placement the endpoint accepts, with the dimensions an
             * advertiser has to supply artwork for. `mobile` is null where a
             * slot has no mobile variant.
             */
            'slots' => collect(config('ads.slots', []))
                ->map(fn (array $slot, string $key): array => [
                    'value' => $key,
                    'label' => $slot['label'],
                    'group' => $slot['group'] ?? null,
                    'width' => $slot['width'],
                    'height' => $slot['height'],
                    // Espees per day, from the admin rate card (config as fallback).
                    'rate' => (int) ($dailyRates[$key] ?? ($slot['rate'] ?? 0)),
                    'dimensions' => $slot['width'] . '×' . $slot['height'] . ' px',
                    'mobileWidth' => $slot['mobile'][0] ?? null,
                    'mobileHeight' => $slot['mobile'][1] ?? null,
                    'mobileDimensions' => isset($slot['mobile'])
                        ? $slot['mobile'][0] . '×' . $slot['mobile'][1] . ' mobile'
                        : null,
                ])
                ->values()
                ->all(),

            /*
             * The form is for members: the endpoint bounces a guest back with
             * `advertising_auth_required` rather than accepting the inquiry.
             * Null here is what makes the screen show the register prompt
             * instead of a form that cannot submit.
             */
            'advertiser' => $user ? [
                'name' => mb_trim($user->firstname . ' ' . $user->lastname),
                'email' => $user->email,
                /*
                 * Read off the raw attributes: the app runs with
                 * `preventAccessingMissingAttributes`, and a User model that
                 * was built without this column selected would throw rather
                 * than return null.
                 */
                'kcHandle' => $user->getAttributes()['kc_handle'] ?? null,
            ] : null,

            /*
             * Whether this advertiser may actually build a campaign. The self-
             * service builder is only for members who own a fully verified,
             * active business — the same gate the SBO network uses — so an
             * unverified account is shown the "get verified first" prompt
             * rather than a form the placement is not open to them yet.
             */
            'verified' => $user
                ? app(\App\Support\SboNetworkAccess::class)->userCanUseNetwork($user)
                : false,

            // Rendered only when both keys are set; see ValidTurnstileToken.
            'turnstileSiteKey' => app(TurnstileVerifier::class)->enabled()
                ? config('services.turnstile.site_key')
                : null,
        ]);
    }
}
