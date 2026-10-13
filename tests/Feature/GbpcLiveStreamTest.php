<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Support\GbpcLiveStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * The GBPC landing page's live-stream URL: a public endpoint the static page
 * fetches, and an admin editor on the GBPC Registrations page.
 */

test('the public stream-url endpoint returns the default until one is set', function (): void {
    $this->getJson(route('gbpc.stream-url'))
        ->assertOk()
        ->assertJson(['url' => GbpcLiveStream::DEFAULT]);
});

test('the public stream-url endpoint returns the saved url', function (): void {
    GbpcLiveStream::setUrl('https://stream.example.com/live/playlist.m3u8');

    $this->getJson(route('gbpc.stream-url'))
        ->assertOk()
        ->assertJson(['url' => 'https://stream.example.com/live/playlist.m3u8']);
});

test('an admin saves the live-stream url from the GBPC registrations page', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);

    Volt::actingAs($admin)->test('admin.gbpc-registrations')
        ->set('streamUrl', 'https://stream.example.com/new/playlist.m3u8')
        ->call('saveStreamUrl')
        ->assertHasNoErrors();

    expect(GbpcLiveStream::url())->toBe('https://stream.example.com/new/playlist.m3u8');
});

test('the live-stream url must be a valid url', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);

    Volt::actingAs($admin)->test('admin.gbpc-registrations')
        ->set('streamUrl', 'not-a-url')
        ->call('saveStreamUrl')
        ->assertHasErrors('streamUrl');

    expect(GbpcLiveStream::url())->toBe(GbpcLiveStream::DEFAULT);
});

test('a non-admin cannot save the live-stream url', function (): void {
    $member = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);

    Volt::actingAs($member)->test('admin.gbpc-registrations')->assertForbidden();
});
