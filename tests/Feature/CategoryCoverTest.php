<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * A cover image per category.
 *
 * Every category shared the site's stock hero video, so browsing from
 * Construction to Churches & Ministries changed the heading and nothing else.
 */

beforeEach(function (): void {
    Storage::fake('public');
});

test('an admin sets a cover and the category serves it', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
    ]);
    $category = Category::query()->firstOrCreate(['name' => 'Construction'], ['slug' => 'construction']);

    Livewire\Volt\Volt::actingAs($admin)
        ->test('admin.categories-locations')
        ->call('chooseCover', $category->id)
        ->set('cover', UploadedFile::fake()->image('site.jpg', 1600, 600));

    expect($category->fresh()->coverUrl())->not->toBeNull();
});

test('a second upload replaces the first rather than stacking', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
    ]);
    $category = Category::query()->firstOrCreate(['name' => 'Construction'], ['slug' => 'construction']);

    $component = Livewire\Volt\Volt::actingAs($admin)->test('admin.categories-locations');

    foreach (['one.jpg', 'two.jpg'] as $name) {
        $component->call('chooseCover', $category->id)
            ->set('cover', UploadedFile::fake()->image($name, 1600, 600));
    }

    // A category has a cover, not a gallery.
    expect($category->fresh()->getMedia(Category::COVER_COLLECTION))->toHaveCount(1);
});

test('clearing a cover returns the category to the shared hero', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
    ]);
    $category = Category::query()->firstOrCreate(['name' => 'Construction'], ['slug' => 'construction']);

    $component = Livewire\Volt\Volt::actingAs($admin)
        ->test('admin.categories-locations')
        ->call('chooseCover', $category->id)
        ->set('cover', UploadedFile::fake()->image('site.jpg', 1600, 600));

    $component->call('removeCover', $category->id);

    expect($category->fresh()->coverUrl())->toBeNull();
});

test('a missing category cover file reports null rather than a dead url', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
    ]);
    $category = Category::query()->firstOrCreate(['name' => 'Construction'], ['slug' => 'construction']);

    Livewire\Volt\Volt::actingAs($admin)
        ->test('admin.categories-locations')
        ->call('chooseCover', $category->id)
        ->set('cover', UploadedFile::fake()->image('missing-category.jpg', 1600, 600));

    $media = $category->fresh()->getFirstMedia(Category::COVER_COLLECTION);
    Storage::disk('public')->delete($media->getPathRelativeToRoot());
    Storage::disk('public')->delete($media->getPathRelativeToRoot('banner'));

    expect($category->fresh()->coverUrl())->toBeNull();
});

test('a file that is not an image is refused', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
    ]);
    $category = Category::query()->firstOrCreate(['name' => 'Construction'], ['slug' => 'construction']);

    Livewire\Volt\Volt::actingAs($admin)
        ->test('admin.categories-locations')
        ->call('chooseCover', $category->id)
        ->set('cover', UploadedFile::fake()->create('rates.pdf', 100, 'application/pdf'))
        ->assertHasErrors('cover');

    expect($category->fresh()->coverUrl())->toBeNull();
});

test('the categories page serves the cover only for the category it filters on', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
    ]);
    $category = Category::query()->firstOrCreate(['name' => 'Construction'], ['slug' => 'construction']);

    Livewire\Volt\Volt::actingAs($admin)
        ->test('admin.categories-locations')
        ->call('chooseCover', $category->id)
        ->set('cover', UploadedFile::fake()->image('site.jpg', 1600, 600));

    $viewer = paidMember();

    $this->actingAs($viewer)
        ->get(route('categories.index', ['category' => 'Construction']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->whereNot('categoryCover', null));

    // Unfiltered, and for a category with no cover, the shared hero shows.
    $this->actingAs($viewer)
        ->get(route('categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('categoryCover', null));
});
