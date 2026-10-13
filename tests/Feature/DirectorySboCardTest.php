<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * The four buttons on a directory card. Three were inert `<button>`s and the
 * fourth ("Connect") was enabled purely on `verified`, which said nothing
 * about whether a connection existed.
 */

function directoryUser(): User
{
    return User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
}

function directorySbo(User $owner, string $name, array $overrides = []): Sbo
{
    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
        'category' => 'Technology Services',
        'address' => '12 Adeola Odeku Street',
        'city' => 'Lagos',
        'state' => 'Lagos State',
        'country' => 'Nigeria',
    ], $overrides));
}

test('a card carries the website and a map query for its address', function (): void {
    $viewer = directoryUser();
    directorySbo($viewer, 'Viewer Ltd');
    directorySbo(directoryUser(), 'Mapped Ltd', ['website' => 'mapped.example']);

    $this->actingAs($viewer)
        ->get(route('businesses.index'))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $listings = collect($page->toArray()['props']['listings']['data']);
            $mapped = $listings->firstWhere('name', 'Mapped Ltd');

            expect($mapped['website'])->toBe('mapped.example')
                ->and($mapped['mapQuery'])
                ->toBe('Mapped Ltd, 12 Adeola Odeku Street, Lagos, Lagos State, Nigeria');
        });
});

test('the connect state reflects the connection, not the verification badge', function (): void {
    $viewer = directoryUser();
    $mine = directorySbo($viewer, 'Viewer Ltd');

    $none = directorySbo(directoryUser(), 'Unconnected Ltd');
    $pending = directorySbo(directoryUser(), 'Pending Ltd');
    $accepted = directorySbo(directoryUser(), 'Accepted Ltd');
    $declined = directorySbo(directoryUser(), 'Declined Ltd');

    SboConnection::create([
        'requester_sbo_id' => $mine->id,
        'recipient_sbo_id' => $pending->id,
        'status' => SboConnection::STATUS_PENDING,
    ]);
    // Reversed direction: they asked us. Still "pending" from either side.
    SboConnection::create([
        'requester_sbo_id' => $accepted->id,
        'recipient_sbo_id' => $mine->id,
        'status' => SboConnection::STATUS_ACCEPTED,
        'responded_at' => now(),
    ]);
    SboConnection::create([
        'requester_sbo_id' => $mine->id,
        'recipient_sbo_id' => $declined->id,
        'status' => SboConnection::STATUS_DECLINED,
        'responded_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->get(route('businesses.index'))
        ->assertInertia(function ($page) use ($mine, $none, $pending, $accepted, $declined): void {
            $props = $page->toArray()['props'];
            $states = collect($props['listings']['data'])->pluck('connection', 'id');

            expect($props['mySboId'])->toBe($mine->id)
                ->and($states[$mine->id])->toBe('self')
                ->and($states[$none->id])->toBe('none')
                ->and($states[$pending->id])->toBe('pending')
                ->and($states[$accepted->id])->toBe('accepted')
                // A declined request is not a dead end — it reads as Connect.
                ->and($states[$declined->id])->toBe('none');
        });
});

test('a viewer with no listing of their own is walled like everyone else', function (): void {
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    directorySbo(directoryUser(), 'Somebody Ltd');

    /*
     * The platform is paid to use, so this viewer never sees a card to act on.
     * `mySboId` still exists for the case that matters — an owner whose only
     * listing is paid but who is browsing someone else's — but a member with
     * no listing is sent to register instead.
     */
    $this->actingAs($viewer)
        ->get(route('businesses.index'))
        ->assertRedirect(route('sbo.payment-required'));
});

test('the connect button posts a real connection request', function (): void {
    $viewer = directoryUser();
    $mine = directorySbo($viewer, 'Viewer Ltd');
    $target = directorySbo(directoryUser(), 'Target Ltd');

    $this->actingAs($viewer)
        ->from(route('businesses.index'))
        ->post(route('sbo.connections.store'), [
            'requester_sbo_id' => $mine->id,
            'recipient_sbo_id' => $target->id,
        ])
        ->assertRedirect(route('businesses.index'));

    expect(SboConnection::where('requester_sbo_id', $mine->id)
        ->where('recipient_sbo_id', $target->id)
        ->where('status', SboConnection::STATUS_PENDING)
        ->exists())->toBeTrue();
});

test('you cannot send a connection request as an sbo you do not own', function (): void {
    $viewer = directoryUser();
    directorySbo($viewer, 'Viewer Ltd');
    $notMine = directorySbo(directoryUser(), 'Not Mine Ltd');
    $target = directorySbo(directoryUser(), 'Target Ltd');

    $this->actingAs($viewer)
        ->post(route('sbo.connections.store'), [
            'requester_sbo_id' => $notMine->id,
            'recipient_sbo_id' => $target->id,
        ])
        ->assertSessionHasErrors('requester_sbo_id');

    expect(SboConnection::count())->toBe(0);
});
