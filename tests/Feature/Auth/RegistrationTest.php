<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

test('registration screen can be rendered', function (): void {
    // As with login: this redirected home while registration was modal-only.
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/RegisterView')
            // The member form's selects are populated from real reference data.
            ->has('zones')
            ->has('countries'));
});

test('new users can register', function (): void {
    $response = Volt::test('auth.register')
        ->set('firstname', 'Test')
        ->set('lastname', 'User')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice', absolute: false));

    $this->assertAuthenticated();
});

test('registration requires turnstile when configured', function (): void {
    config([
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);

    Volt::test('auth.register')
        ->set('firstname', 'Test')
        ->set('lastname', 'User')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertHasErrors(['turnstileToken']);

    $this->assertGuest();
});

test('registration accepts a valid turnstile token when configured', function (): void {
    config([
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);

    Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true]),
    ]);

    Volt::test('auth.register')
        ->set('firstname', 'Test')
        ->set('lastname', 'User')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->set('turnstileToken', 'valid-token')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice', absolute: false));

    $this->assertAuthenticated();
});
