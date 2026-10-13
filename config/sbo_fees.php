<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SBO Registration Fees
|--------------------------------------------------------------------------
|
| The registration fee an SBO pays is based on the company's share capital.
| The SBO first picks a "share structure"; share-bearing structures (private
| and public companies) then estimate their equity size (issued shares ×
| per-share value) which maps to a fee band. Non-share structures pay a
| single flat fee.
|
| ALL AMOUNTS AND TYPES BELOW ARE PLACEHOLDERS. The Ministry of Commerce
| (MOC), Loveworld Nation swaps in the official fee schedule here — no code
| change required. Fees are quoted in Espees; payment is made to the Espees
| Merchant code "MOCDII1" via the Espees Max app.
|
*/

return [

    // Currency the fees are quoted in.
    'currency' => 'Espees',

    // Where SBOs send the payment.
    'merchant' => [
        'name' => 'MOCDII1',
        'app' => 'Espees Max',
    ],

    /*
    | The four share-structure options presented in the up-front gate.
    | `shares => true` means the structure has share capital and proceeds to
    | the per-share fee flow; `false` skips to the flat fee.
    */
    'structures' => [
        'private' => ['label' => 'Private company (shares issued privately)', 'shares' => true],
        'public' => ['label' => 'Public company (shares issued publicly)', 'shares' => true],
        'no_shares' => ['label' => 'No shares / limited by guarantee', 'shares' => false],
        'sole_partnership' => ['label' => 'Sole proprietor / partnership', 'shares' => false],
    ],

    /*
    | Share-capital types offered per share-bearing structure. Private and
    | public may differ; adjust as the MOC requires. PLACEHOLDER values.
    */
    'share_capital_types' => [
        'private' => [
            'ordinary' => 'Ordinary Shares',
            'preference' => 'Preference Shares',
        ],
        'public' => [
            'ordinary' => 'Ordinary Shares',
            'preference' => 'Preference Shares',
            'redeemable' => 'Redeemable Shares',
        ],
    ],

    /*
    | Fee bands per share-bearing structure. Each band matches when the equity
    | size (issued shares × per-share value) is <= `max_equity`. The final
    | band MUST have `max_equity => null` (open-ended, catches everything
    | above the previous band). Bands are evaluated top to bottom, so keep
    | them in ascending order of `max_equity`. `fee` is in Espees.
    |
    | PLACEHOLDER numbers — replace with the official MOC schedule.
    */
    'share_bands' => [
        'private' => [
            ['max_equity' => 1_000_000, 'fee' => 50, 'label' => 'Up to 1,000,000'],
            ['max_equity' => 10_000_000, 'fee' => 150, 'label' => '1,000,001 – 10,000,000'],
            ['max_equity' => 100_000_000, 'fee' => 400, 'label' => '10,000,001 – 100,000,000'],
            ['max_equity' => null, 'fee' => 1_000, 'label' => 'Above 100,000,000'],
        ],
        'public' => [
            ['max_equity' => 10_000_000, 'fee' => 250, 'label' => 'Up to 10,000,000'],
            ['max_equity' => 100_000_000, 'fee' => 750, 'label' => '10,000,001 – 100,000,000'],
            ['max_equity' => 1_000_000_000, 'fee' => 2_000, 'label' => '100,000,001 – 1,000,000,000'],
            ['max_equity' => null, 'fee' => 5_000, 'label' => 'Above 1,000,000,000'],
        ],
    ],

    /*
    | Single flat fee (Espees) for all non-share structures (no_shares and
    | sole_partnership). PLACEHOLDER — replace with the official amount.
    */
    'flat_fee' => 25,

];
