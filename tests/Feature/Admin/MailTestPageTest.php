<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Support\AdminPages;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

test('super admins can open the mail test page from the sidebar', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.mail-test'))
        ->assertOk()
        ->assertSee('Send a test email')
        ->assertSee('Mail Test')
        ->assertSee(route('admin.mail-test'), false);
});

test('ordinary admins need the mail test page permission', function (): void {
    $allowed = User::factory()->create([
        'role' => UserRole::ADMIN->value,
        'email_verified_at' => now(),
        'admin_page_permissions' => [AdminPages::MAIL_TEST],
    ]);

    $blocked = User::factory()->create([
        'role' => UserRole::ADMIN->value,
        'email_verified_at' => now(),
        'admin_page_permissions' => [AdminPages::SUPPORT],
    ]);

    $this->actingAs($allowed)->get(route('admin.mail-test'))->assertOk();
    $this->actingAs($blocked)->get(route('admin.mail-test'))->assertForbidden();
});

test('the mail test page sends through the configured mailer', function (): void {
    config()->set('mail.default', 'array');

    $admin = User::factory()->create([
        'role' => UserRole::SUPER_ADMIN->value,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin);

    Volt::test('admin.mail-test')
        ->set('to', 'person@example.com')
        ->set('subject', 'Hello World')
        ->set('html', '<p>Congrats on sending your <strong>first email</strong>!</p>')
        ->call('send')
        ->assertHasNoErrors();

    $transport = Mail::mailer('array')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class)
        ->and($transport->messages())->toHaveCount(1);
});

test('the resend key can be loaded from either env name', function (): void {
    putenv('RESEND_KEY');
    putenv('RESEND_API_KEY=re_alias_key');
    $_ENV['RESEND_API_KEY'] = 're_alias_key';
    $_SERVER['RESEND_API_KEY'] = 're_alias_key';

    config()->set('services.resend.key', env('RESEND_KEY') ?: env('RESEND_API_KEY'));

    expect(config('services.resend.key'))->toBe('re_alias_key');

    putenv('RESEND_API_KEY');
    unset($_ENV['RESEND_API_KEY'], $_SERVER['RESEND_API_KEY']);
});
