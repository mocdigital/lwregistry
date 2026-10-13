<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ad Placement Slots
|--------------------------------------------------------------------------
|
| Single source of truth for ad placements across the platform. The admin
| upload form and the strict dimension validator both read from here, and
| the <x-ad-slot name="..."> component renders whatever active ad is
| assigned to a slot.
|
| Each slot defines its EXACT required creative size (width x height in px)
| and an optional mobile fallback [w, h] used for responsive rendering.
|
*/

return [

    'slots' => [

        'home_hero' => [
            'label' => 'Home – Hero Billboard',
            'rate' => 500, // Espees per day
            'group' => 'Home',
            'width' => 970,
            'height' => 250,
            'mobile' => [320, 100],
        ],

        'home_mid' => [
            'label' => 'Home – Mid Leaderboard',
            'rate' => 300, // Espees per day
            'group' => 'Home',
            'width' => 728,
            'height' => 90,
            'mobile' => [320, 100],
        ],

        'directory_sidebar' => [
            'label' => 'Directory – Sidebar',
            'rate' => 250, // Espees per day
            'group' => 'Directory',
            'width' => 300,
            'height' => 250,
            'mobile' => null,
        ],

        'directory_inline' => [
            'label' => 'Directory – In-feed Leaderboard',
            'rate' => 300, // Espees per day
            'group' => 'Directory',
            'width' => 728,
            'height' => 90,
            'mobile' => [320, 100],
        ],

        'company_sidebar' => [
            'label' => 'Company Detail – Sidebar',
            'rate' => 200, // Espees per day
            'group' => 'Company',
            'width' => 300,
            'height' => 250,
            'mobile' => null,
        ],

        'company_below_about' => [
            'label' => 'Company Detail – Below About',
            'rate' => 200, // Espees per day
            'group' => 'Company',
            'width' => 728,
            'height' => 90,
            'mobile' => [320, 100],
        ],

        'dashboard_top' => [
            'label' => 'Dashboard – Top Leaderboard',
            'rate' => 250, // Espees per day
            'group' => 'Dashboard',
            'width' => 728,
            'height' => 90,
            'mobile' => [320, 100],
        ],

        'global_footer' => [
            'label' => 'Global – Above Footer',
            'rate' => 300, // Espees per day
            'group' => 'Global',
            'width' => 970,
            'height' => 90,
            'mobile' => [320, 100],
        ],

    ],

    /*
    | Impression throttling: don't count more than one impression per ad per
    | this many minutes from the same session, to avoid inflated counts.
    */
    'impression_throttle_minutes' => 30,

];
