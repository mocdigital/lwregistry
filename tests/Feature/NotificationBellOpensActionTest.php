<?php

declare(strict_types=1);

use App\Livewire\NotificationBell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * A notification is a signpost: clicking one should take the reader to the
 * thing it is about — the report queue, the enquiry, the conversation — not
 * merely clear its dot.
 */

function bellNotification(User $user, ?string $url): void
{
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => ['title' => 'Something happened', 'message' => 'Go and look.', 'url' => $url],
        'read_at' => null,
    ]);
}

test('opening a notification marks it read and redirects to its action', function (): void {
    $user = User::factory()->create();
    bellNotification($user, '/admin/support?enquiry=7');

    $id = $user->notifications()->first()->id;

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('open', $id)
        ->assertRedirect('/admin/support?enquiry=7');

    expect($user->notifications()->first()->read_at)->not->toBeNull();
});

test('a notification with no destination just marks read, no redirect', function (): void {
    $user = User::factory()->create();
    bellNotification($user, null);

    $id = $user->notifications()->first()->id;

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('open', $id)
        ->assertNoRedirect();

    expect($user->notifications()->first()->read_at)->not->toBeNull();
});

test('a user cannot open a notification that is not theirs', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    bellNotification($owner, '/admin/support');

    $id = $owner->notifications()->first()->id;

    Livewire::actingAs($intruder)
        ->test(NotificationBell::class)
        ->call('open', $id)
        ->assertNoRedirect();

    // Untouched: it was never theirs to read.
    expect($owner->notifications()->first()->read_at)->toBeNull();
});
