<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Subscriber;
use App\Models\User;

/*
 * "Subscribe Now" on the home rail.
 *
 * It was a button with no handler under copy promising alerts. These are the
 * decisions worth pinning: a guest can subscribe (the card is on the public
 * home page precisely for them), subscribing twice is not an error, and
 * unsubscribing keeps the row.
 */

test('a guest subscribes and is told so', function (): void {
    $this->post(route('subscribe'), [
        'name' => 'Ada Okafor',
        'email' => 'Ada@Example.Test',
    ])->assertRedirect();

    $subscriber = Subscriber::firstOrFail();

    expect($subscriber->name)->toBe('Ada Okafor')
        // Normalised, or the same person subscribing from a phone keyboard
        // that capitalises the first letter becomes a second row.
        ->and($subscriber->email)->toBe('ada@example.test')
        ->and($subscriber->source)->toBe(Subscriber::SOURCE_HOME_RAIL)
        ->and($subscriber->user_id)->toBeNull()
        ->and($subscriber->isActive())->toBeTrue();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'flash.subscribed',
            'You are on the list. Alerts will go to ada@example.test.',
        ));
});

test('a signed-in member is linked to their account', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->post(route('subscribe'), [
        'name' => 'Ada Okafor',
        'email' => 'ada@example.test',
    ]);

    expect(Subscriber::firstOrFail()->user_id)->toBe($user->id);
});

test('subscribing twice updates the row rather than failing', function (): void {
    $this->post(route('subscribe'), ['name' => 'Ada O', 'email' => 'ada@example.test']);
    $this->post(route('subscribe'), ['name' => 'Ada Okafor', 'email' => 'ada@example.test'])
        ->assertSessionHasNoErrors();

    expect(Subscriber::count())->toBe(1)
        ->and(Subscriber::firstOrFail()->name)->toBe('Ada Okafor');
});

test('asking again after unsubscribing puts you back on the list', function (): void {
    Subscriber::create([
        'name' => 'Ada Okafor',
        'email' => 'ada@example.test',
        'unsubscribed_at' => now()->subDay(),
    ]);

    $this->post(route('subscribe'), ['name' => 'Ada Okafor', 'email' => 'ada@example.test']);

    expect(Subscriber::firstOrFail()->isActive())->toBeTrue();
});

test('a name and a real email address are both required', function (): void {
    $this->post(route('subscribe'), ['email' => 'ada@example.test'])
        ->assertSessionHasErrors('name');

    $this->post(route('subscribe'), ['name' => 'Ada', 'email' => 'not-an-address'])
        ->assertSessionHasErrors('email');

    expect(Subscriber::count())->toBe(0);
});

test('the admin list shows subscribers and unsubscribing keeps the row', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
    ]);

    $subscriber = Subscriber::create(['name' => 'Ada Okafor', 'email' => 'ada@example.test']);

    $this->actingAs($admin)
        ->get(route('admin.subscribers.index'))
        ->assertOk()
        ->assertSee('ada@example.test')
        ->assertSee('Opportunity alerts');

    Livewire\Volt\Volt::actingAs($admin)
        ->test('admin.subscribers.index')
        ->call('unsubscribe', $subscriber->id);

    $subscriber->refresh();

    // Kept, not deleted: a deleted row cannot stop the address being re-added
    // by an import, and it destroys the record that consent was withdrawn.
    expect($subscriber->exists)->toBeTrue()
        ->and($subscriber->isActive())->toBeFalse()
        ->and(Subscriber::query()->active()->count())->toBe(0);
});
