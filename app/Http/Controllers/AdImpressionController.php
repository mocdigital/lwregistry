<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdPlacement;
use App\Support\AdService;
use Symfony\Component\HttpFoundation\Response;

/**
 * A view beacon for the SPA.
 *
 * The Blade `<x-ad-slot>` recorded an impression as it rendered server-side.
 * The Vue slot cannot — the ad arrives as a shared prop — so when it actually
 * paints a real ad it calls this, and the count is thrown away or kept by the
 * same per-session throttle the Blade path used.
 */
final class AdImpressionController
{
    public function __invoke(AdPlacement $ad): Response
    {
        AdService::recordImpression($ad);

        return response()->noContent();
    }
}
