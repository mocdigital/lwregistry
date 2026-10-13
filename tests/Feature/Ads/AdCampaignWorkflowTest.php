<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Models\AdRateCard;
use App\Models\User;
use App\Notifications\AdCampaignApprovedNotification;
use App\Notifications\AdCampaignRejectedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

function adCampaignOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
    ], $overrides));
}

function adCampaignRate(string $slot = 'home_hero'): AdRateCard
{
    return AdRateCard::query()->where('slot', $slot)->where('duration_days', 7)->firstOrFail();
}

function adCampaignPayload(AdRateCard $rate, array $overrides = []): array
{
    return array_merge([
        'slot' => $rate->slot,
        'ad_rate_card_id' => $rate->id,
        'title' => 'July Promo',
        'cta_text' => 'Visit Us',
        'cta_url' => 'https://example.com/promo',
        'desired_start_date' => now()->addDay()->toDateString(),
        'desktop_creative' => UploadedFile::fake()->image('desktop.png', 970, 250),
        'mobile_creative' => UploadedFile::fake()->image('mobile.png', 320, 100),
    ], $overrides);
}

function paidAdCampaign(User $owner, string $slot = 'home_hero'): AdCampaign
{
    $rate = adCampaignRate($slot);

    $campaign = AdCampaign::create([
        'user_id' => $owner->id,
        'ad_rate_card_id' => $rate->id,
        'slot' => $rate->slot,
        'title' => 'Paid Campaign',
        'cta_text' => 'Learn More',
        'cta_url' => 'https://example.com',
        'duration_days' => $rate->duration_days,
        'price' => $rate->price,
        'currency' => 'Espees',
        'status' => AdCampaign::STATUS_PAID_PENDING_REVIEW,
        'payment_reference' => 'PAYREF-ADS',
        'payment_confirmed_at' => now(),
    ]);

    $desktop = UploadedFile::fake()->image('desktop.png', 970, 250);
    $mobile = UploadedFile::fake()->image('mobile.png', 320, 100);

    $campaign->addMedia($desktop)
        ->usingFileName('desktop.png')
        ->toMediaCollection('desktop_creative');
    $campaign->addMedia($mobile)
        ->usingFileName('mobile.png')
        ->toMediaCollection('mobile_creative');

    return $campaign;
}

test('authenticated users can create a campaign with exact desktop and mobile creatives', function (): void {
    Storage::fake('public');

    $owner = adCampaignOwner();
    $rate = adCampaignRate();

    $this->actingAs($owner)
        ->from(route('advertise'))
        ->post(route('advertise.campaigns.store'), adCampaignPayload($rate))
        ->assertRedirect(route('advertise') . '#advertise-campaign-builder')
        ->assertSessionHas('advertising_campaign_success');

    $campaign = AdCampaign::query()->firstOrFail();

    expect($campaign->status)->toBe(AdCampaign::STATUS_PENDING_PAYMENT)
        ->and($campaign->price)->toBe($rate->price)
        ->and($campaign->duration_days)->toBe(7)
        ->and($campaign->desktopImageUrl())->not->toBeNull()
        ->and($campaign->mobileImageUrl())->not->toBeNull();
});

test('campaign creation accepts a larger creative of the right proportions', function (): void {
    Storage::fake('public');

    $owner = adCampaignOwner();
    $rate = adCampaignRate();

    // 970x250 slot; these are twice the size but the identical shape, which is
    // exactly what the strict pixel rule used to reject.
    $this->actingAs($owner)
        ->from(route('advertise'))
        ->post(route('advertise.campaigns.store'), adCampaignPayload($rate, [
            'desktop_creative' => UploadedFile::fake()->image('big.png', 1940, 500),
            'mobile_creative' => UploadedFile::fake()->image('big-mobile.png', 640, 200),
        ]))
        ->assertRedirect(route('advertise') . '#advertise-campaign-builder')
        ->assertSessionHasNoErrors();

    expect(AdCampaign::query()->count())->toBe(1);
});

test('campaign creation still rejects a creative of the wrong shape', function (): void {
    Storage::fake('public');

    $owner = adCampaignOwner();
    $rate = adCampaignRate();

    // Right minimum size, wrong proportions for the 970x250 slot.
    $this->actingAs($owner)
        ->from(route('advertise'))
        ->post(route('advertise.campaigns.store'), adCampaignPayload($rate, [
            'desktop_creative' => UploadedFile::fake()->image('square.png', 1000, 1000),
        ]))
        ->assertRedirect(route('advertise'))
        ->assertSessionHasErrors('desktop_creative');

    expect(AdCampaign::query()->count())->toBe(0);
});

test('campaign creation rejects invalid dimensions and missing mobile creative', function (): void {
    Storage::fake('public');

    $owner = adCampaignOwner();
    $rate = adCampaignRate();

    $this->actingAs($owner)
        ->from(route('advertise'))
        ->post(route('advertise.campaigns.store'), adCampaignPayload($rate, [
            'desktop_creative' => UploadedFile::fake()->image('bad.png', 300, 250),
            'mobile_creative' => null,
        ]))
        ->assertRedirect(route('advertise'))
        ->assertSessionHasErrors(['desktop_creative', 'mobile_creative']);
});

test('inactive rate packages cannot be purchased and campaign price is locked', function (): void {
    Storage::fake('public');

    $owner = adCampaignOwner();
    $rate = adCampaignRate();
    $originalPrice = $rate->price;

    $this->actingAs($owner)
        ->post(route('advertise.campaigns.store'), adCampaignPayload($rate))
        ->assertRedirect(route('advertise') . '#advertise-campaign-builder');

    $campaign = AdCampaign::query()->firstOrFail();
    $rate->update(['price' => $originalPrice + 999, 'is_active' => false]);

    expect($campaign->fresh()->price)->toBe($originalPrice);

    $this->actingAs($owner)
        ->from(route('advertise'))
        ->post(route('advertise.campaigns.store'), adCampaignPayload($rate))
        ->assertRedirect(route('advertise'))
        ->assertSessionHasErrors(['ad_rate_card_id']);
});

test('admin approval creates a live ad placement and notifies the advertiser', function (): void {
    Storage::fake('public');
    Notification::fake();

    $owner = adCampaignOwner();
    $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN->value]);
    $campaign = paidAdCampaign($owner);

    Volt::actingAs($admin)
        ->test('admin.ads.index')
        ->call('approveCampaign', $campaign->id);

    $campaign->refresh();
    $ad = AdPlacement::query()->firstOrFail();

    expect($campaign->ad_placement_id)->toBe($ad->id)
        ->and($campaign->status)->toBeIn([AdCampaign::STATUS_ACTIVE, AdCampaign::STATUS_APPROVED])
        ->and($ad->slot)->toBe('home_hero')
        ->and($ad->imageUrl())->not->toBeNull()
        ->and($ad->mobileImageUrl())->not->toBeNull();

    Notification::assertSentTo($owner, AdCampaignApprovedNotification::class);
});

test('admin rejection stores a reason and notifies the advertiser', function (): void {
    Notification::fake();

    $owner = adCampaignOwner();
    $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN->value]);
    $campaign = paidAdCampaign($owner);

    Volt::actingAs($admin)
        ->test('admin.ads.index')
        ->set('rejectingCampaignId', $campaign->id)
        ->set('rejectionReason', 'Creative includes unreadable text.')
        ->call('rejectCampaign');

    $campaign->refresh();

    expect($campaign->status)->toBe(AdCampaign::STATUS_REJECTED)
        ->and($campaign->rejection_reason)->toBe('Creative includes unreadable text.');

    Notification::assertSentTo($owner, AdCampaignRejectedNotification::class);
});

test('admin can update advertising rate cards from ads management', function (): void {
    $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN->value]);
    $rate = adCampaignRate();

    Volt::actingAs($admin)
        ->test('admin.ads.index')
        ->assertSee('Rate Cards')
        ->assertSee('Home – Hero Billboard')
        ->set("rateDrafts.{$rate->id}.price", $rate->price + 125)
        ->set("rateDrafts.{$rate->id}.is_active", false)
        ->call('updateRateCard', $rate->id);

    $rate->refresh();

    expect($rate->price)->toBe(575)
        ->and($rate->is_active)->toBeFalse();
});
