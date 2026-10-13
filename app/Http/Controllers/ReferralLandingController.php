<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Zone;
use Illuminate\Http\RedirectResponse;

/**
 * Landing point for a zone referral link (/join/{code}). Records the referral
 * in the session so the join form can pre-select the zone and tag the resulting
 * registration to it, then sends the visitor to the join page. Unknown or
 * inactive codes degrade gracefully to the plain home page.
 */
final class ReferralLandingController
{
    /** Session keys holding the pending zone referral. */
    public const SESSION_CODE = 'zone_referral_code';

    public const SESSION_NAME = 'zone_referral_name';

    public function __invoke(string $code): RedirectResponse
    {
        $zone = Zone::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (null === $zone) {
            return redirect()->route('home');
        }

        session([
            self::SESSION_CODE => $zone->code,
            self::SESSION_NAME => $zone->name,
        ]);

        return redirect()->route('join');
    }
}
