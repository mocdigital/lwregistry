<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\AuthModal;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Volt\Volt;

use function Pest\Laravel\assertDatabaseHas;

test('user registration sets default role and redirects successfully', function (): void {
    $userData = [
        'firstname' => 'John',
        'lastname' => 'Doe',
        'email' => 'john.doe@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    Volt::test('auth.register')
        ->set('firstname', $userData['firstname'])
        ->set('lastname', $userData['lastname'])
        ->set('email', $userData['email'])
        ->set('password', $userData['password'])
        ->set('password_confirmation', $userData['password_confirmation'])
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice', absolute: false));

    assertDatabaseHas('users', [
        'email' => $userData['email'],
        'role' => UserRole::USER->value,
    ]);

    $user = User::where('email', $userData['email'])->first();
    expect($user->role)->toBe(UserRole::USER);
    expect($user->isAdmin())->toBeFalse();
    expect(Hash::check($userData['password'], $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

test('new user model has default role before saving', function (): void {
    $user = new User();
    $user->firstname = 'Jane';
    $user->lastname = 'Smith';
    $user->email = 'jane.smith@example.com';
    $user->password = Hash::make('password');

    // Role should be set even before saving to database
    expect($user->role)->toBe(UserRole::USER);
    expect($user->isAdmin())->toBeFalse();

    $user->save();

    expect($user->fresh()->role)->toBe(UserRole::USER);
});

test('user login works correctly', function (): void {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
    ]);

    Livewire::test(AuthModal::class)
        ->set('loginEmail', 'test@example.com')
        ->set('loginPassword', 'password123')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('user registration validates required fields', function (): void {
    Volt::test('auth.register')
        ->set('firstname', '')
        ->set('lastname', '')
        ->set('email', '')
        ->set('password', '')
        ->set('password_confirmation', '')
        ->call('register')
        ->assertHasErrors(['firstname', 'lastname', 'email', 'password']);
});

test('user registration validates password confirmation match', function (): void {
    Volt::test('auth.register')
        ->set('firstname', 'John')
        ->set('lastname', 'Doe')
        ->set('email', 'john@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'differentpassword')
        ->call('register')
        ->assertHasErrors(['password' => 'confirmed']);
});

test('user registration validates unique email', function (): void {
    User::factory()->create(['email' => 'existing@example.com']);

    Volt::test('auth.register')
        ->set('firstname', 'John')
        ->set('lastname', 'Doe')
        ->set('email', 'existing@example.com')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasErrors(['email']);
});
