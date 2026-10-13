<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * The platform is paid to use, so every authed destination sits behind
 * `sbo_payment_approved`. These tests are about the page, not the gate, so
 * their actor is given a paid listing.
 */
function profilePagePaidListing(User $user): void
{
    $name = 'Profile Page SBO ' . Illuminate\Support\Str::random(6);

    App\Models\Sbo::create([
        'name' => $name,
        'slug' => Illuminate\Support\Str::slug($name) . '-' . Illuminate\Support\Str::random(5),
        'created_by' => $user->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);
}

test('profile page requires authentication', function (): void {
    $this->get(route('account.profile'))
        ->assertRedirect(route('login'));
});

test('authenticated user can view the Loveworld profile page', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('profile-photos/avatar.png', 'avatar');

    $user = User::factory()->create([
        'firstname' => 'Profile',
        'lastname' => 'Owner',
        'email' => 'profile-owner@example.com',
        'kc_handle' => '@profile_owner',
        'profile_photo_path' => 'profile-photos/avatar.png',
    ]);

    Business::factory()->verified()->create([
        'user_id' => $user->id,
        'views_count' => 12,
    ]);

    Business::factory()->unverified()->create([
        'user_id' => $user->id,
        'views_count' => 8,
    ]);

    profilePagePaidListing($user);

    $response = $this->actingAs($user)
        ->get(route('account.profile'))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->component('account/ProfileDashboardView'));

    /*
     * The nav items the old assertions covered (Edit profile, Security,
     * Support, Go to listings) are static links in the Vue screen, so they are
     * no longer server data. The identity and the two metric tiles are, and the
     * photo and handle in particular are asserted because the screen used to
     * draw the Figma stock avatar for every member regardless of what they had
     * uploaded.
     */
    expect(inertiaProp($response, 'user.email'))->toBe('profile-owner@example.com')
        ->and(inertiaProp($response, 'user.handle'))->toBe('@profile_owner')
        ->and(inertiaProp($response, 'user.photoUrl'))->toContain('profile-photos/avatar.png')
        ->and(inertiaProp($response, 'stats.listings'))->toBe(1)
        ->and(inertiaProp($response, 'stats.views'))->toBe(20);
});
