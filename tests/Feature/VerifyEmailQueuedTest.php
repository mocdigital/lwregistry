<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\VerifyEmailQueued;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    VerifyEmailQueued::$createUrlCallback = null;
    VerifyEmailQueued::$toMailCallback = null;
});

afterEach(function (): void {
    VerifyEmailQueued::$createUrlCallback = null;
    VerifyEmailQueued::$toMailCallback = null;
});

test('notification uses database connection and auth queue', function (): void {
    $notification = new VerifyEmailQueued();

    expect($notification->connection)->toBe('database');
    expect($notification->queue)->toBe('auth');
});

test('user verification resend sends immediately instead of waiting for a worker', function (): void {
    Notification::fake();
    Queue::fake();

    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmailQueued::class);
    Queue::assertNothingPushed();
});

test('notification has correct properties', function (): void {
    $notification = new VerifyEmailQueued();

    expect($notification->tries)->toBe(3);
    expect($notification->timeout)->toBe(30);
});

test('notification returns mail channel', function (): void {
    $user = User::factory()->create();
    $notification = new VerifyEmailQueued();

    $channels = $notification->via($user);

    expect($channels)->toBe(['mail']);
});

test('notification creates verification mail message', function (): void {
    $user = User::factory()->create();
    $notification = new VerifyEmailQueued();

    $mailMessage = $notification->toMail($user);

    expect($mailMessage)->toBeInstanceOf(MailMessage::class);
});

test('notification uses custom url callback when set', function (): void {
    $user = User::factory()->create();
    $notification = new VerifyEmailQueued();
    $customUrl = 'https://custom-verification-url.test';

    VerifyEmailQueued::createUrlUsing(fn () => $customUrl);

    $mailMessage = $notification->toMail($user);
    $reflection = new ReflectionClass($mailMessage);
    $property = $reflection->getProperty('actionUrl');
    $actionUrl = $property->getValue($mailMessage);

    expect($actionUrl)->toBe($customUrl);
});

test('notification uses custom mail callback when set', function (): void {
    $user = User::factory()->create();
    $notification = new VerifyEmailQueued();
    $customMessage = new MailMessage();
    $customMessage->subject('Custom Subject');

    VerifyEmailQueued::toMailUsing(fn () => $customMessage);

    $mailMessage = $notification->toMail($user);

    expect($mailMessage)->toBe($customMessage);
});

test('notification generates default verification url', function (): void {
    $user = User::factory()->create();
    $notification = new VerifyEmailQueued();

    $reflection = new ReflectionClass($notification);
    $method = $reflection->getMethod('verificationUrl');
    $url = $method->invoke($notification, $user);

    expect($url)->toBeString();
    expect($url)->toContain('/email/verify/');
    expect($url)->toContain((string) $user->getKey());
    expect($url)->toContain(sha1((string) $user->email));
});

test('notification builds default mail message with correct content', function (): void {
    $user = User::factory()->create();
    $notification = new VerifyEmailQueued();

    $reflection = new ReflectionClass($notification);
    $method = $reflection->getMethod('buildMailMessage');
    $mailMessage = $method->invoke($notification, 'https://test-url.com');

    expect($mailMessage)->toBeInstanceOf(MailMessage::class);
    expect($mailMessage->subject)->toBe('Verify Email Address');
    expect($mailMessage->introLines)->toContain('Please click the button below to verify your email address.');
    expect($mailMessage->outroLines)->toContain('If you did not create an account, no further action is required.');
});

test('notification respects verification timeout config', function (): void {
    $user = User::factory()->create();
    $notification = new VerifyEmailQueued();

    Config::set('auth.verification.expire', 120);

    $reflection = new ReflectionClass($notification);
    $method = $reflection->getMethod('verificationUrl');
    $url = $method->invoke($notification, $user);

    expect($url)->toBeString();
    expect($url)->toContain('expires=');
});

test('static properties can be null by default', function (): void {
    expect(VerifyEmailQueued::$createUrlCallback)->toBeNull();
    expect(VerifyEmailQueued::$toMailCallback)->toBeNull();
});

test('create url using accepts closure', function (): void {
    $callback = (fn () => 'test-url');

    VerifyEmailQueued::createUrlUsing($callback);

    expect(VerifyEmailQueued::$createUrlCallback)->toBe($callback);
});

test('to mail using accepts closure', function (): void {
    $callback = (fn () => new MailMessage());

    VerifyEmailQueued::toMailUsing($callback);

    expect(VerifyEmailQueued::$toMailCallback)->toBe($callback);
});
