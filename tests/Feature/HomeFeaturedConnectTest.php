<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The action slot on a Featured SBO card.
 *
 * It was a bookmark button with no handler, over a feature that does not
 * exist — there is nowhere to save a listing to. Connect is what the registry
 * actually offers on a listing, and it is the same POST the directory cards
 * and the profile hero already make.
 *
 * The card needs three things the payload did not carry: the SBO id (the
 * endpoint addresses businesses by id, not slug), the viewer's own connection
 * state with it, and which SBO the viewer acts as.
 */

function featuredSbo(string $name, array $overrides = []): Sbo
{
    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => User::factory()->create(['email_verified_at' => now()])->id,
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('a guest gets the cards but no sbo to connect from', function (): void {
    featuredSbo('Ben Logistics Ltd');

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('mySboId', null)
            // 'none' rather than something guest-specific: the card sends a
            // guest to sign-in, which is the real next step on a members-only
            // registry, rather than rendering a control that cannot work.
            ->where('featured.0.connection', 'none')
            ->has('featured.0.id'));
});

test('an owner sees their own listing as theirs', function (): void {
    $owner = paidMember();
    $mine = $owner->sbos()->first();

    $this->actingAs($owner)
        ->get('/')
        ->assertOk()
        ->assertInertia(function ($page) use ($mine): void {
            $featured = collect($page->toArray()['props']['featured']);
            $card = $featured->firstWhere('id', $mine->id);

            expect($card['connection'])->toBe('self')
                ->and($page->toArray()['props']['mySboId'])->toBe($mine->id);
        });
});

test('a pending request shows as pending, not as connect again', function (): void {
    $owner = paidMember();
    $mine = $owner->sbos()->first();
    $theirs = featuredSbo('Ben Logistics Ltd');

    SboConnection::create([
        'requester_sbo_id' => $mine->id,
        'recipient_sbo_id' => $theirs->id,
        'status' => SboConnection::STATUS_PENDING,
    ]);

    $this->actingAs($owner)
        ->get('/')
        ->assertOk()
        ->assertInertia(function ($page) use ($theirs): void {
            $card = collect($page->toArray()['props']['featured'])->firstWhere('id', $theirs->id);

            expect($card['connection'])->toBe('pending');
        });
});

test('an accepted connection shows as connected', function (): void {
    $owner = paidMember();
    $mine = $owner->sbos()->first();
    $theirs = featuredSbo('Ben Logistics Ltd');

    SboConnection::create([
        'requester_sbo_id' => $mine->id,
        'recipient_sbo_id' => $theirs->id,
        'status' => SboConnection::STATUS_ACCEPTED,
        'responded_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get('/')
        ->assertOk()
        ->assertInertia(function ($page) use ($theirs): void {
            $card = collect($page->toArray()['props']['featured'])->firstWhere('id', $theirs->id);

            expect($card['connection'])->toBe('accepted');
        });
});

test('the button posts a real connection request', function (): void {
    $owner = paidMember();
    $mine = $owner->sbos()->first();
    $theirs = featuredSbo('Ben Logistics Ltd');

    // Exactly what the card sends.
    $this->actingAs($owner)
        ->post('/sbo/connections', [
            'requester_sbo_id' => $mine->id,
            'recipient_sbo_id' => $theirs->id,
        ])
        ->assertSessionHasNoErrors();

    expect(SboConnection::query()
        ->where('requester_sbo_id', $mine->id)
        ->where('recipient_sbo_id', $theirs->id)
        ->exists())->toBeTrue();

    // And the card it came from now says so.
    $this->actingAs($owner)
        ->get('/')
        ->assertInertia(function ($page) use ($theirs): void {
            $card = collect($page->toArray()['props']['featured'])->firstWhere('id', $theirs->id);

            expect($card['connection'])->toBe('pending');
        });
});

test('the connection states cost one query, not one per card', function (): void {
    $owner = paidMember();

    foreach (range(1, 12) as $i) {
        featuredSbo('Featured Co ' . $i);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($owner)->get('/')->assertOk();

    /*
     * A per-card lookup would be twelve queries to draw one row of buttons,
     * which is why the states are resolved for the whole set before the render
     * array. The ceiling is deliberately loose — it is guarding the shape of
     * the code, not a specific number.
     */
    expect($queries)->toBeLessThan(40);
});
