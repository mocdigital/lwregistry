<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdPlacement;
use App\Support\AdService;
use Illuminate\Http\RedirectResponse;

final class AdClickController
{
    public function __invoke(AdPlacement $ad): RedirectResponse
    {
        AdService::recordClick($ad);

        if ( ! empty($ad->cta_url)) {
            return redirect()->away($ad->cta_url);
        }

        return redirect()->back();
    }
}
