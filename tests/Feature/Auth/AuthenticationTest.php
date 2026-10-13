<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Livewire\Volt\Volt as LivewireVolt;

test('login screen can be rendered', function (): void {
    // `GET /login` used to redirect home, because sign-in existed only as a
    // Livewire modal. It serves the Vue screen now.
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/LoginView'));
});

test('users can authenticate using the login screen', function (): void {
    $user = User::factory()->create();

    $response = LivewireVolt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('post-login', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function (): void {
    $user = User::factory()->create();

    $response = LivewireVolt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login');

    $response->assertHasErrors('email');

    $this->assertGuest();
});

test('users can logout', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('expired logout token redirects home instead of showing 419', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->withMiddleware(ValidateCsrfToken::class)
        ->actingAs($user)
        ->post(route('logout'), [], ['HTTP_X_CSRF_TOKEN' => 'expired-token']);

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
