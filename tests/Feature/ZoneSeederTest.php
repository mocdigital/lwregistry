<?php

declare(strict_types=1);

use App\Models\Zone;
use App\Support\ChurchZones;
use Database\Seeders\ZoneSeeder;

it('seeds every church zone with a unique code, idempotently', function (): void {
    $expected = count(ChurchZones::all());

    (new ZoneSeeder())->run();

    expect(Zone::count())->toBe($expected)
        ->and(Zone::whereNull('code')->count())->toBe(0)
        ->and(Zone::distinct()->count('code'))->toBe($expected)
        ->and(Zone::where('is_active', true)->count())->toBe($expected);

    // Re-running must not duplicate or change existing codes.
    $codesBefore = Zone::orderBy('name')->pluck('code', 'name');
    (new ZoneSeeder())->run();

    expect(Zone::count())->toBe($expected)
        ->and(Zone::orderBy('name')->pluck('code', 'name')->all())->toBe($codesBefore->all());
});
