<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AdPlacement;
use App\Models\AdvertisingInquiry;
use App\Models\User;
use App\Notifications\AdvertisingInquirySubmittedNotification;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Route::get('/_test-ad-slot/{slot}', fn (string $slot): string => Blade::render(
        '<x-ad-slot :name="$slot" />',
        ['slot' => $slot],
    ));
});

test('empty ad slot renders advertise with us fallback banner', function (): void {
    $this->get('/_test-ad-slot/home_hero')
        ->assertOk()
        ->assertSee('Advertisement space')
        ->assertSee('Advertise with Us')
        ->assertSee('970×250 px')
        ->assertSee('320×100 mobile')
        ->assertSee(url('/advertise'), false);
});

test('active paid ad renders creative instead of advertise fallback', function (): void {
    $ad = AdPlacement::create([
        'slot' => 'home_hero',
        'title' => 'Paid Registry Campaign',
        'cta_text' => 'Learn More',
        'cta_url' => 'https://example.com/campaign',
        'priority' => 10,
        'is_active' => true,
    ]);

    $mediaId = DB::table('media')->insertGetId([
        'model_type' => AdPlacement::class,
        'model_id' => $ad->id,
        'uuid' => (string) Str::uuid(),
        'collection_name' => 'creative',
        'name' => 'paid-banner',
        'file_name' => 'paid-banner.png',
        'mime_type' => 'image/png',
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => 1024,
        'manipulations' => '[]',
        'custom_properties' => '[]',
        'generated_conversions' => '[]',
        'responsive_images' => '[]',
        'order_column' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /*
     * The row alone is no longer enough. AdPlacement::imageUrl() checks the
     * file is really on the disk before returning a URL — added with the /media
     * route, because a media row whose file never made it to the server used to
     * render as a broken image rather than falling back to the house banner.
     * So the fixture has to put the file there, as a real upload would.
     */
    $media = Media::findOrFail($mediaId);

    Storage::disk('public')->put(
        $media->getPathRelativeToRoot(),
        'not-really-a-png',
    );

    $this->get('/_test-ad-slot/home_hero')
        ->assertOk()
        ->assertSee('Paid Registry Campaign')
        ->assertSee(route('ads.click', $ad), false)
        ->assertDontSee('Advertise with Us');

    expect($ad->fresh()->impressions)->toBe(1);
});

test('advertise page is public and lists configured placement dimensions', function (): void {
    $response = $this->get(route('advertise'))->assertOk();

    $slots = collect(inertiaProp($response, 'slots'));

    /*
     * The placements come from config/ads.php, which is also what
     * AdvertisingInquiryController validates `ad_location` against — a screen
     * offering any other set would be offering choices the endpoint rejects.
     * The cards used to hardcode these nine names and sizes.
     */
    expect($slots->pluck('value')->all())->toEqualCanonicalizing(array_keys(config('ads.slots')));

    $hero = $slots->firstWhere('value', 'home_hero');
    expect($hero['label'])->toBe('Home – Hero Billboard')
        ->and($hero['dimensions'])->toBe('970×250 px')
        ->and($hero['mobileDimensions'])->toBe('320×100 mobile');

    // A guest gets no advertiser identity, which is what makes the screen show
    // the register prompt instead of a form the endpoint would refuse.
    expect(inertiaProp($response, 'advertiser'))->toBeNull();
});

test('the advertise screen carries the banner dimensions each placement needs', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)->get(route('advertise'))->assertOk();

    /*
     * The Blade screen carried these as `data-desktop-width` attributes for a
     * jQuery helper to read. The dimensions are the contract; how the screen
     * shows them is the component's business.
     */
    $slots = collect(inertiaProp($response, 'slots'));

    $hero = $slots->firstWhere('value', 'home_hero');
    expect($hero['width'])->toBe(970)
        ->and($hero['height'])->toBe(250)
        ->and($hero['mobileWidth'])->toBe(320)
        ->and($hero['mobileHeight'])->toBe(100);

    // A slot with no mobile variant says so rather than guessing a size.
    $noMobile = $slots->first(fn (array $s): bool => null === $s['mobileWidth']);
    expect($noMobile)->not->toBeNull()
        ->and($noMobile['mobileDimensions'])->toBeNull();
});

test('a signed-in advertiser gets their account details prefilled', function (): void {
    $user = User::factory()->create([
        'firstname' => 'Ada',
        'lastname' => 'Okafor',
        'email' => 'ada@example.test',
        'kc_handle' => '@ada',
    ]);

    $response = $this->actingAs($user)->get(route('advertise'))->assertOk();

    expect(inertiaProp($response, 'advertiser.name'))->toBe('Ada Okafor')
        ->and(inertiaProp($response, 'advertiser.email'))->toBe('ada@example.test')
        ->and(inertiaProp($response, 'advertiser.kcHandle'))->toBe('@ada');
});

test('the turnstile site key reaches the screen only when it is configured', function (): void {
    // Not configured: no key, so the widget is not drawn — and the endpoint
    // does not require a token either.
    expect(inertiaProp(
        $this->actingAs(User::factory()->create())->get(route('advertise'))->assertOk(),
        'turnstileSiteKey',
    ))->toBeNull();

    config([
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);

    expect(inertiaProp(
        $this->actingAs(User::factory()->create())->get(route('advertise'))->assertOk(),
        'turnstileSiteKey',
    ))->toBe('test-site-key');
});

test('guest advertising inquiry redirects to registration prompt without notifying admins', function (): void {
    Notification::fake();

    config([
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);

    $this->from(route('advertise'))
        ->post(route('advertise.inquiry'), [
            'name' => 'Ada Okafor',
            'kc_handle' => '@ada-kc',
            'email' => 'ada@example.test',
            'ad_location' => 'home_hero',
            'message' => 'We want to advertise a new campaign next month.',
        ])
        ->assertRedirect(route('advertise') . '#advertise-inquiry')
        ->assertSessionHas('advertising_auth_required', true)
        ->assertSessionHas('advertising_auth_message');

    Notification::assertNothingSent();
});

test('advertising inquiry sends an email notification to admins', function (): void {
    Notification::fake();

    $advertiser = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($advertiser)
        ->from(route('advertise'))
        ->post(route('advertise.inquiry'), [
            'name' => 'Ada Okafor',
            'kc_handle' => '@ada-kc',
            'email' => 'ada@example.test',
            'ad_location' => 'home_hero',
            'message' => 'We want to advertise a new campaign next month.',
            'cf-turnstile-response' => 'valid-token',
        ])
        ->assertRedirect(route('advertise') . '#advertise-inquiry')
        ->assertSessionHas('advertising_inquiry_success');

    Notification::assertSentTo($admin, AdvertisingInquirySubmittedNotification::class);

    $this->assertDatabaseHas('advertising_inquiries', [
        'user_id' => $advertiser->id,
        'name' => 'Ada Okafor',
        'kc_handle' => '@ada-kc',
        'email' => 'ada@example.test',
        'ad_location' => 'home_hero',
        'message' => 'We want to advertise a new campaign next month.',
        'status' => AdvertisingInquiry::STATUS_NEW,
    ]);
});

test('advertising inquiry validation redirects with the entered fields preserved', function (): void {
    $advertiser = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($advertiser)
        ->from(route('advertise'))
        ->post(route('advertise.inquiry'), [
            'name' => 'Keep This Name',
            'kc_handle' => '@keep-this',
            'email' => 'invalid-email',
            'ad_location' => 'home_hero',
            'message' => 'Keep this message while the email is corrected.',
        ])
        ->assertRedirect(route('advertise'))
        ->assertSessionHasErrors(['email'])
        ->assertSessionHasInput('name', 'Keep This Name')
        ->assertSessionHasInput('kc_handle', '@keep-this')
        ->assertSessionHasInput('message', 'Keep this message while the email is corrected.');

    expect(AdvertisingInquiry::count())->toBe(0);
});

test('advertising inquiry validates turnstile when configured', function (): void {
    Notification::fake();

    config([
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);

    Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true]),
    ]);

    $advertiser = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($advertiser)
        ->from(route('advertise'))
        ->post(route('advertise.inquiry'), [
            'name' => 'Ada Okafor',
            'kc_handle' => '@ada-kc',
            'email' => 'ada@example.test',
            'ad_location' => 'home_hero',
            'message' => 'We want to advertise a new campaign next month.',
            'cf-turnstile-response' => 'valid-token',
        ])
        ->assertRedirect(route('advertise') . '#advertise-inquiry');

    Notification::assertSentTo($admin, AdvertisingInquirySubmittedNotification::class);
});

test('advertising inquiry rejects missing turnstile when configured', function (): void {
    Notification::fake();

    config([
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);

    $this->actingAs(User::factory()->create())
        ->from(route('advertise'))
        ->post(route('advertise.inquiry'), [
            'name' => 'Ada Okafor',
            'kc_handle' => '@ada-kc',
            'email' => 'ada@example.test',
            'ad_location' => 'home_hero',
            'message' => 'We want to advertise a new campaign next month.',
        ])
        ->assertRedirect(route('advertise'))
        ->assertSessionHasErrors(['cf-turnstile-response']);

    Notification::assertNothingSent();
});

test('advertising inquiry rejects invalid turnstile when configured', function (): void {
    Notification::fake();

    config([
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);

    Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => false]),
    ]);

    $this->actingAs(User::factory()->create())
        ->from(route('advertise'))
        ->post(route('advertise.inquiry'), [
            'name' => 'Ada Okafor',
            'kc_handle' => '@ada-kc',
            'email' => 'ada@example.test',
            'ad_location' => 'home_hero',
            'message' => 'We want to advertise a new campaign next month.',
            'cf-turnstile-response' => 'invalid-token',
        ])
        ->assertRedirect(route('advertise'))
        ->assertSessionHasErrors(['cf-turnstile-response']);

    Notification::assertNothingSent();
});

test('advertising inquiry falls back to configured super admin email when no admin users exist', function (): void {
    Notification::fake();

    config(['app.super_admin_email' => 'super-admin@example.test']);

    $this->actingAs(User::factory()->create())
        ->from(route('advertise'))
        ->post(route('advertise.inquiry'), [
            'name' => 'Pastor Chris',
            'kc_handle' => '@pcdl',
            'email' => 'pcdl@example.test',
            'ad_location' => 'global_footer',
            'message' => 'Please send footer placement availability.',
        ])
        ->assertRedirect(route('advertise') . '#advertise-inquiry');

    Notification::assertSentOnDemand(AdvertisingInquirySubmittedNotification::class);
});

test('advertising inquiry rejects missing fields and unknown ad locations', function (): void {
    Notification::fake();

    $this->actingAs(User::factory()->create())
        ->from(route('advertise'))
        ->post(route('advertise.inquiry'), [
            'name' => '',
            'kc_handle' => '',
            'email' => 'not-an-email',
            'ad_location' => 'unknown_slot',
            'message' => '',
        ])
        ->assertRedirect(route('advertise'))
        ->assertSessionHasErrors(['name', 'kc_handle', 'email', 'ad_location', 'message']);

    Notification::assertNothingSent();
});

test('the advertise workflow is reachable from the public entry points', function (): void {
    /*
     * Was `assertSee(route('advertise'))` against the rendered page. The link
     * lives in Vue components (the footer's Advertisers column, the directory's
     * marketing rail) that the server never describes, so the reachable thing
     * to assert from PHP is that the destination resolves for the visitors
     * those entry points serve.
     */
    $this->get(route('home'))->assertOk();

    // Public: a guest can reach the advertise screen and is told to register.
    $this->get(route('advertise'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('public/AdvertiseView')->where('advertiser', null));

    // And a member reaches it with the form ready to submit.
    $member = paidMember();
    $this->actingAs($member)->get(route('businesses.index'))->assertOk();
    $this->actingAs($member)
        ->get(route('advertise'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('advertiser.email', $member->email));
});
