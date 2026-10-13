<?php

declare(strict_types=1);

use App\Models\AdPlacement;
use App\Models\User;
use App\Support\AdService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function liveAd(string $slot, int $width, int $height): AdPlacement
{
    $ad = AdPlacement::create([
        'slot' => $slot,
        'title' => ucfirst($slot) . ' banner',
        'cta_text' => 'Learn More',
        'cta_url' => 'https://example.com',
        'priority' => 100,
        'is_active' => true,
        'created_by' => User::factory()->create()->id,
    ]);

    $creative = UploadedFile::fake()->image('creative.png', $width, $height);

    $ad->addMedia($creative)
        ->usingFileName('creative.png')
        ->toMediaCollection('creative');

    return $ad;
}

test('a slot with a live ad is shared for the SPA, and an empty slot is null', function (): void {
    Storage::fake('public');

    $ad = liveAd('directory_sidebar', 300, 250);

    $slots = AdService::sharedSlots();

    expect($slots['directory_sidebar'])->not->toBeNull()
        ->and($slots['directory_sidebar']['id'])->toBe($ad->id)
        ->and($slots['directory_sidebar']['clickUrl'])->toBe(route('ads.click', $ad))
        ->and($slots['directory_sidebar']['seenUrl'])->toBe(route('ads.seen', $ad))
        ->and($slots['directory_sidebar']['image'])->not->toBeNull()
        // Every other slot has no ad, so it is null — the component renders
        // nothing for it rather than a placeholder.
        ->and($slots['home_hero'])->toBeNull()
        ->and($slots['company_sidebar'])->toBeNull();
});

test('an inactive ad is not shared', function (): void {
    Storage::fake('public');

    $ad = liveAd('home_hero', 970, 250);
    $ad->update(['is_active' => false]);

    expect(AdService::sharedSlots()['home_hero'])->toBeNull();
});

test('the view beacon records an impression', function (): void {
    Storage::fake('public');

    $ad = liveAd('home_hero', 970, 250);

    $this->get(route('ads.seen', $ad))->assertNoContent();

    expect($ad->fresh()->impressions)->toBe(1);
});
