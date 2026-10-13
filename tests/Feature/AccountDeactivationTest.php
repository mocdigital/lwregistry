<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Self-service account deactivation.
 *
 * The "Deactivate Account" button was inert, and the copy beside it promised a
 * permanent delete the app cannot safely perform: `sbos.created_by` is
 * nullOnDelete, so removing the user row would leave their approved listings
 * in the public directory with no owner and no way for anyone to edit them
 * again. Deactivation suspends instead, which every other part of the app
 * already understands.
 */

function deactivationUser(): User
{
    return User::factory()->create([
        'password' => Hash::make('correct-horse-1A'),
        'email_verified_at' => now(),
    ]);
}

test('typing DELETE with the right password deactivates the account', function (): void {
    $user = deactivationUser();

    $this->actingAs($user)
        ->post('/settings/deactivate', [
            'confirmation' => 'DELETE',
            'password' => 'correct-horse-1A',
        ])
        ->assertRedirect('/');

    expect($user->refresh()->isSuspended())->toBeTrue();

    // Signed out as part of the same action, not left holding a live session.
    $this->assertGuest();
});

test('the account row survives, so its listings keep their owner', function (): void {
    $user = deactivationUser();
    $sbo = Sbo::create([
        'name' => 'Kingdom Media Works',
        'slug' => 'kingdom-' . Str::random(6),
        'created_by' => $user->id,
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
    ]);

    $this->actingAs($user)->post('/settings/deactivate', [
        'confirmation' => 'DELETE',
        'password' => 'correct-horse-1A',
    ]);

    /*
     * The whole reason this suspends rather than deletes. A deleted user would
     * null created_by and strand this listing: publicly visible, permanently
     * uneditable, because every write path authorizes on created_by.
     */
    expect(User::find($user->id))->not->toBeNull()
        ->and($sbo->refresh()->created_by)->toBe($user->id);
});

test('the wrong confirmation word does nothing', function (): void {
    $user = deactivationUser();

    foreach (['delete', 'Delete', 'DELETE ME', ''] as $attempt) {
        $this->actingAs($user)
            ->post('/settings/deactivate', [
                'confirmation' => $attempt,
                'password' => 'correct-horse-1A',
            ])
            ->assertSessionHasErrors('confirmation');
    }

    expect($user->refresh()->isSuspended())->toBeFalse();
});

test('the right word with the wrong password does nothing', function (): void {
    $user = deactivationUser();

    $this->actingAs($user)
        ->post('/settings/deactivate', [
            'confirmation' => 'DELETE',
            'password' => 'not-my-password',
        ])
        ->assertSessionHasErrors('password');

    expect($user->refresh()->isSuspended())->toBeFalse();
});

test('a deactivated account cannot sign back in', function (): void {
    $user = deactivationUser();

    $this->actingAs($user)->post('/settings/deactivate', [
        'confirmation' => 'DELETE',
        'password' => 'correct-horse-1A',
    ]);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-1A'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('deactivating is signed-in only', function (): void {
    $this->post('/settings/deactivate', [
        'confirmation' => 'DELETE',
        'password' => 'correct-horse-1A',
    ])->assertRedirect(route('login'));
});
