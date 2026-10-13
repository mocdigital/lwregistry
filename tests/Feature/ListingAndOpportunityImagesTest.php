<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\Sbo;
use App\Models\User;
use App\Support\LiveOpportunities;
use App\Support\SboListingProfilePayload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Card imagery.
 *
 * Every listing card and every opportunity row drew artwork chosen by list
 * POSITION: HomeView.vue rotated three stock photos and three invented partner
 * logos across the whole registry, and LiveOpportunities.vue rotated three more
 * across every request — so a road-construction photo could illustrate a
 * request for accountancy services, and no two businesses could be told apart.
 * Both are real uploads now, with the stock artwork kept only as a fallback.
 */

function imageOwner(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

function imageSbo(User $owner): Sbo
{
    return Sbo::create([
        'name' => 'Ben Logistics Ltd',
        'slug' => 'ben-logistics-' . Str::random(6),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);
}

/* ------------------------------------------------------- listing cards --- */

test('an owner can set a cover image and a logo', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);

    expect($sbo->coverUrl())->toBeNull()
        ->and($sbo->logoUrl())->toBeNull();

    $this->actingAs($owner)
        ->post("/sbo/listings/{$sbo->id}/branding", [
            'collection' => 'cover',
            'image' => UploadedFile::fake()->image('yard.jpg', 1200, 600),
        ])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post("/sbo/listings/{$sbo->id}/branding", [
            'collection' => 'logo',
            'image' => UploadedFile::fake()->image('logo.png', 400, 400),
        ])
        ->assertRedirect();

    $sbo->refresh()->load('media');

    expect($sbo->coverUrl())->not->toBeNull()
        ->and($sbo->logoUrl())->not->toBeNull();
});

test('re-uploading replaces rather than accumulating', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);

    foreach (['first.jpg', 'second.jpg'] as $name) {
        $this->actingAs($owner)->post("/sbo/listings/{$sbo->id}/branding", [
            'collection' => 'cover',
            'image' => UploadedFile::fake()->image($name, 1200, 600),
        ]);
    }

    // singleFile: a listing has one cover, not a pile of them.
    expect($sbo->refresh()->getMedia('cover'))->toHaveCount(1)
        ->and($sbo->getFirstMedia('cover')->file_name)->toContain('second');
});

test('branding can be removed again', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);

    $this->actingAs($owner)->post("/sbo/listings/{$sbo->id}/branding", [
        'collection' => 'logo',
        'image' => UploadedFile::fake()->image('logo.png'),
    ]);

    $this->actingAs($owner)
        ->delete("/sbo/listings/{$sbo->id}/branding/logo")
        ->assertRedirect();

    expect($sbo->refresh()->logoUrl())->toBeNull();
});

test('an unknown branding collection is refused', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);

    $this->actingAs($owner)
        ->post("/sbo/listings/{$sbo->id}/branding", [
            'collection' => 'gallery_office',
            'image' => UploadedFile::fake()->image('sneaky.jpg'),
        ])
        ->assertSessionHasErrors('collection');

    $this->actingAs($owner)
        ->delete("/sbo/listings/{$sbo->id}/branding/two_factor_secret")
        ->assertNotFound();
});

test('branding belongs to the listing owner alone', function (): void {
    Storage::fake('public');

    $theirs = imageSbo(imageOwner());

    $this->actingAs(imageOwner())
        ->post("/sbo/listings/{$theirs->id}/branding", [
            'collection' => 'cover',
            'image' => UploadedFile::fake()->image('not-mine.jpg'),
        ])
        ->assertNotFound();

    expect($theirs->refresh()->coverUrl())->toBeNull();
});

test('the editor is told the current branding so it can preview it', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);

    $payload = SboListingProfilePayload::for($sbo);
    expect($payload['branding'])->toBe(['logoUrl' => null, 'coverUrl' => null]);

    $this->actingAs($owner)->post("/sbo/listings/{$sbo->id}/branding", [
        'collection' => 'cover',
        'image' => UploadedFile::fake()->image('yard.jpg', 1200, 600),
    ]);

    expect(SboListingProfilePayload::for($sbo->fresh())['branding']['coverUrl'])->not->toBeNull();
});

/* --------------------------------------------------- opportunity cards --- */

test('a buyer can attach a featured image when posting a request', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);
    $category = Category::query()->firstOrCreate(
        ['name' => 'Transport & Logistics'],
        ['slug' => 'transport-logistics'],
    );

    $this->actingAs($owner)->post('/procurement', [
        'sbo_id' => $sbo->id,
        'title' => 'Fleet servicing contract',
        'description' => 'Annual servicing for a 40-vehicle fleet.',
        'category' => $category->name,
        'request_type' => 'service',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
        'featured_image' => UploadedFile::fake()->image('fleet.jpg', 1200, 675),
    ])->assertRedirect();

    $order = PurchaseOrder::where('title', 'Fleet servicing contract')->firstOrFail();

    expect($order->featuredImageUrl())->not->toBeNull();
});

test('a request without an image still posts, and reports no image', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);
    $category = Category::query()->firstOrCreate(
        ['name' => 'Transport & Logistics'],
        ['slug' => 'transport-logistics'],
    );

    $this->actingAs($owner)->post('/procurement', [
        'sbo_id' => $sbo->id,
        'title' => 'Imageless request',
        'description' => 'No photo attached.',
        'category' => $category->name,
        'request_type' => 'product',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
    ])->assertRedirect();

    $order = PurchaseOrder::where('title', 'Imageless request')->firstOrFail();

    // Null, not a stock photo — the screen decides the fallback, not the server.
    expect($order->featuredImageUrl())->toBeNull();
});

test('the live opportunities rail carries each request own image', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);
    $category = Category::query()->firstOrCreate(
        ['name' => 'Transport & Logistics'],
        ['slug' => 'transport-logistics'],
    );

    $this->actingAs($owner)->post('/procurement', [
        'sbo_id' => $sbo->id,
        'title' => 'Railed request',
        'description' => 'Shown on the rail.',
        'category' => $category->name,
        'request_type' => 'service',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
        'order_deadline' => now()->addDays(10)->toDateString(),
        'featured_image' => UploadedFile::fake()->image('rail.jpg', 1200, 675),
    ]);

    LiveOpportunities::flush();

    $row = collect(LiveOpportunities::forRail())->firstWhere('title', 'Railed request');

    expect($row)->not->toBeNull()
        ->and($row['imageUrl'])->not->toBeNull();
});

test('the live opportunities rail labels deadlines instead of exposing translation keys', function (): void {
    $owner = imageOwner();
    $sbo = imageSbo($owner);
    $category = Category::query()->firstOrCreate(
        ['name' => 'Transport & Logistics'],
        ['slug' => 'transport-logistics'],
    );
    $deadline = now()->addDays(10);

    PurchaseOrder::create([
        'sbo_id' => $sbo->id,
        'category_id' => $category->id,
        'title' => 'Deadline-labelled request',
        'description' => 'Shown on the rail with a readable deadline.',
        'request_type' => 'service',
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
        'order_deadline' => $deadline->toDateString(),
    ]);

    PurchaseOrder::create([
        'sbo_id' => $sbo->id,
        'category_id' => $category->id,
        'title' => 'Ongoing request',
        'description' => 'Shown on the rail without a date.',
        'request_type' => 'service',
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
    ]);

    LiveOpportunities::flush();

    $rows = collect(LiveOpportunities::forRail())->keyBy('title');

    expect($rows['Deadline-labelled request']['when'])->toBe('Deadline: ' . $deadline->format('M j, Y'))
        ->and($rows['Deadline-labelled request']['when'])->not->toBe('opportunities.deadline')
        ->and($rows['Deadline-labelled request']['urgent'])->toBeTrue()
        ->and($rows['Ongoing request']['when'])->toBe('Ongoing');
});

test('the live opportunities rail cache is separated by locale', function (): void {
    $owner = imageOwner();
    $sbo = imageSbo($owner);
    $category = Category::query()->firstOrCreate(
        ['name' => 'Transport & Logistics'],
        ['slug' => 'transport-logistics'],
    );
    $deadline = now()->addDays(10);

    PurchaseOrder::create([
        'sbo_id' => $sbo->id,
        'category_id' => $category->id,
        'title' => 'Translated deadline request',
        'description' => 'Shown on the rail with locale-specific deadline text.',
        'request_type' => 'service',
        'status' => 'open',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
        'order_deadline' => $deadline->toDateString(),
    ]);

    LiveOpportunities::flush();

    app()->setLocale('en');
    $english = collect(LiveOpportunities::forRail())->firstWhere('title', 'Translated deadline request');

    app()->setLocale('fr');
    $french = collect(LiveOpportunities::forRail())->firstWhere('title', 'Translated deadline request');

    expect($english['when'])->toBe('Deadline: ' . $deadline->format('M j, Y'))
        ->and($french['when'])->toBe('Date limite : ' . $deadline->format('M j, Y'));
});

/* ------------------------------------------- branding reaches the cards --- */

test('an uploaded cover and logo reach every card payload that draws them', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);
    Category::query()->firstOrCreate(
        ['name' => 'Transport & Logistics'],
        ['slug' => 'transport-logistics'],
    );
    $sbo->update(['category' => 'Transport & Logistics']);

    foreach (['cover', 'logo'] as $collection) {
        $this->actingAs($owner)->post("/sbo/listings/{$sbo->id}/branding", [
            'collection' => $collection,
            'image' => UploadedFile::fake()->image("{$collection}.jpg", 900, 600),
        ]);
    }

    /*
     * The upload was landing on the model but never reaching most screens:
     * only the home page had been wired, so the directory, categories, the
     * profile hero and My Account all kept drawing stock artwork.
     */
    $this->actingAs($owner)->get(route('businesses.show', $sbo->slug))->assertInertia(
        function ($page): void {
            $business = $page->toArray()['props']['business'];

            expect($business['coverUrl'])->not->toBeNull()
                ->and($business['logoUrl'])->not->toBeNull();
        },
    );

    $this->actingAs($owner)->get(route('businesses.index'))->assertInertia(function ($page) use ($sbo): void {
        $row = collect($page->toArray()['props']['listings']['data'])->firstWhere('slug', $sbo->slug);

        expect($row['coverUrl'])->not->toBeNull()
            ->and($row['logoUrl'])->not->toBeNull();
    });

    $this->actingAs($owner)->get(route('account.index'))->assertInertia(function ($page) use ($sbo): void {
        $row = collect($page->toArray()['props']['listings']['verified'])
            ->firstWhere('slug', $sbo->slug);

        expect($row['coverUrl'])->not->toBeNull();
    });

    $this->actingAs($owner)->get('/')->assertInertia(function ($page) use ($sbo): void {
        $row = collect($page->toArray()['props']['featured'])->firstWhere('slug', $sbo->slug);

        // Null when the home page is not showing this listing at all.
        if (null !== $row) {
            expect($row['coverUrl'])->not->toBeNull();
        }
    });
});

test('a listing with no uploads reports nulls, so screens can fall back', function (): void {
    $owner = imageOwner();
    $sbo = imageSbo($owner);

    $this->actingAs($owner)->get(route('businesses.show', $sbo->slug))->assertInertia(
        function ($page): void {
            $business = $page->toArray()['props']['business'];

            // Null, not a stock URL — the screen owns the fallback, not the
            // server, so a real upload always wins.
            expect($business['coverUrl'])->toBeNull()
                ->and($business['logoUrl'])->toBeNull();
        },
    );
});

/* ------------------------------------------- conversions and featuring --- */

test('a branding url points at a file that actually exists', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);

    $this->actingAs($owner)->post("/sbo/listings/{$sbo->id}/branding", [
        'collection' => 'cover',
        'image' => UploadedFile::fake()->image('yard.jpg', 1200, 600),
    ]);

    $media = $sbo->refresh()->getFirstMedia('cover');

    /*
     * The bug this guards: conversions are queued by default and the project
     * runs a database queue, so with no worker the 'card' conversion was never
     * generated — while coverUrl() still returned its URL. Uploads rendered as
     * broken images. The conversion is non-queued now, and the accessor falls
     * back to the original if one is ever missing.
     */
    $url = $sbo->coverUrl();
    expect($url)->not->toBeNull();

    $path = $media->hasGeneratedConversion('card')
        ? $media->getPath('card')
        : $media->getPath();

    expect(file_exists($path))->toBeTrue();
});

test('a missing conversion degrades to the original rather than a dead url', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);

    $this->actingAs($owner)->post("/sbo/listings/{$sbo->id}/branding", [
        'collection' => 'cover',
        'image' => UploadedFile::fake()->image('yard.jpg', 1200, 600),
    ]);

    $media = $sbo->refresh()->getFirstMedia('cover');

    // Simulate the queued-but-never-run case.
    $media->generated_conversions = [];
    $media->save();

    expect($sbo->fresh()->coverUrl())->toBe($media->fresh()->getUrl())
        ->and($sbo->fresh()->coverUrl())->not->toContain('conversions');
});

test('a missing original branding file reports null rather than a dead url', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);

    $this->actingAs($owner)->post("/sbo/listings/{$sbo->id}/branding", [
        'collection' => 'cover',
        'image' => UploadedFile::fake()->image('missing.jpg', 1200, 600),
    ]);

    $media = $sbo->refresh()->getFirstMedia('cover');
    Storage::disk('public')->delete($media->getPathRelativeToRoot());
    Storage::disk('public')->delete($media->getPathRelativeToRoot('card'));

    expect($sbo->fresh()->coverUrl())->toBeNull();
});

test('a missing opportunity image file reports null rather than a dead url', function (): void {
    Storage::fake('public');

    $owner = imageOwner();
    $sbo = imageSbo($owner);
    $category = Category::query()->firstOrCreate(
        ['name' => 'Transport & Logistics'],
        ['slug' => 'transport-logistics'],
    );

    $this->actingAs($owner)->post('/procurement', [
        'sbo_id' => $sbo->id,
        'title' => 'Missing opportunity image',
        'description' => 'The DB row exists but the file is gone.',
        'category' => $category->name,
        'request_type' => 'service',
        'visibility' => PurchaseOrder::VISIBILITY_PUBLIC,
        'featured_image' => UploadedFile::fake()->image('missing-rfp.jpg', 1200, 675),
    ]);

    $order = PurchaseOrder::where('title', 'Missing opportunity image')->firstOrFail();
    $media = $order->getFirstMedia(PurchaseOrder::FEATURED_COLLECTION);
    Storage::disk('public')->delete($media->getPathRelativeToRoot());
    Storage::disk('public')->delete($media->getPathRelativeToRoot('card'));

    expect($order->fresh()->featuredImageUrl())->toBeNull();
});

test('listings with a cover lead the home page featured row', function (): void {
    Storage::fake('public');

    $owner = imageOwner();

    // Older, but has artwork.
    $withCover = imageSbo($owner);
    $withCover->forceFill(['created_at' => now()->subYear()])->save();

    // Newer, but nothing uploaded.
    $withoutCover = imageSbo($owner);

    $this->actingAs($owner)->post("/sbo/listings/{$withCover->id}/branding", [
        'collection' => 'cover',
        'image' => UploadedFile::fake()->image('yard.jpg', 1200, 600),
    ]);

    $this->actingAs($owner)->get('/')->assertInertia(function ($page) use ($withCover, $withoutCover): void {
        $slugs = collect($page->toArray()['props']['featured'])->pluck('slug');

        /*
         * Newest-first alone buried any older listing that had done the work of
         * uploading real artwork, which is the wrong way round for a row whose
         * whole job is to look like something.
         */
        expect($slugs->search($withCover->slug))
            ->toBeLessThan($slugs->search($withoutCover->slug));
    });
});

test('home featured cards show state and country as the location line', function (): void {
    $owner = imageOwner();
    $sbo = imageSbo($owner);

    $sbo->forceFill([
        'category' => 'Churches & Ministries',
        'city' => 'Ikeja',
        'state' => 'Lagos',
        'country' => 'Nigeria',
    ])->save();

    $this->actingAs($owner)->get('/')->assertInertia(function ($page) use ($sbo): void {
        $row = collect($page->toArray()['props']['featured'])->firstWhere('slug', $sbo->slug);

        expect($row['meta'])->toBe('Lagos, Nigeria')
            ->and($row['meta'])->not->toContain('Churches & Ministries')
            ->and($row['meta'])->not->toContain('Ikeja');
    });
});
