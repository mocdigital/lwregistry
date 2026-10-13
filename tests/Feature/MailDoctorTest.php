<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * The mail diagnostic.
 *
 * Verification mail is a queued notification, so it can stop in four places
 * that all look the same from outside — never queued, queued and waiting, ran
 * and threw, or "sent" into a log driver. The point of the command is that it
 * names which, so these assert it actually does rather than merely running.
 *
 * Asserted on `Artisan::output()` rather than `expectsOutputToContain`: a
 * command that writes through `$this->components->*` swaps its output writer,
 * and the harness then records only some of what the reader sees — which made
 * a passing expectation prove nothing.
 */

function doctorOutput(array $arguments = []): string
{
    Artisan::call('mail:doctor', $arguments);

    return Artisan::output();
}

test('it names a log driver, which delivers nothing', function (): void {
    config()->set('mail.default', 'log');

    expect(doctorOutput())->toContain('MAIL_MAILER=log');
});

test('it names a backlog on the auth queue, which means no worker', function (): void {
    DB::table('jobs')->insert([
        'queue' => 'auth',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subHour()->timestamp,
        'created_at' => now()->subHour()->timestamp,
    ]);

    $output = doctorOutput();

    expect($output)->toContain('1 verification job(s) waiting')
        // The command a reader can act on, not just the observation.
        ->and($output)->toContain('supervisorctl status registry-worker');
});

test('it reports the last failure rather than only a count', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'auth',
        'payload' => '{}',
        'exception' => "Swift_TransportException: Connection refused\n#0 /app/vendor/…",
        'failed_at' => now(),
    ]);

    $output = doctorOutput();

    // The first line of the exception, not the stack trace under it.
    expect($output)->toContain('Connection refused')
        ->and($output)->not->toContain('#0 /app/vendor');
});

test('a clean setup says so instead of leaving the reader guessing', function (): void {
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.username', 'postmaster@example.test');

    $output = doctorOutput();

    expect($output)->toContain('No configuration fault found')
        ->and($output)->not->toContain('supervisorctl');
});

/* ------------------------------------------------------------- resend --- */

/*
 * Resend refuses anything sent from a domain the account has not verified,
 * and the refusal surfaces as a generic transport failure long after the fact
 * — or, for queued mail, as nothing visible at all. The default from address
 * was hello@example.com, which could never have worked. Asking the account
 * which domains it owns turns that silent dead end into one line.
 */
function fakeResendDomains(array $verified): void
{
    Http::fake([
        'api.resend.com/domains' => Http::response([
            'data' => array_map(
                fn (string $name): array => ['name' => $name, 'status' => 'verified'],
                $verified,
            ),
        ]),
    ]);
}

test('it names a from-domain the resend account cannot send from', function (): void {
    config()->set('mail.default', 'resend');
    config()->set('services.resend.key', 're_test_key');
    config()->set('mail.from.address', 'hello@example.com');

    fakeResendDomains(['lwcommerce.org']);

    expect(doctorOutput())
        ->toContain('@example.com')
        ->toContain('not verified');
});

test('a subdomain does not inherit its parent', function (): void {
    /*
     * lwcommerce.org being verified says nothing about
     * registrytest.lwcommerce.org — Resend treats them as separate domains,
     * and the live site runs on the subdomain, so this is the mistake most
     * likely to be made here.
     */
    config()->set('mail.default', 'resend');
    config()->set('services.resend.key', 're_test_key');
    config()->set('mail.from.address', 'noreply@registrytest.lwcommerce.org');

    fakeResendDomains(['lwcommerce.org']);

    expect(doctorOutput())->toContain('not verified');
});

test('a verified from-domain raises nothing', function (): void {
    config()->set('mail.default', 'resend');
    config()->set('services.resend.key', 're_test_key');
    config()->set('mail.from.address', 'noreply@lwcommerce.org');

    fakeResendDomains(['lwcommerce.org']);

    expect(doctorOutput())
        ->toContain('lwcommerce.org')
        ->not->toContain('not verified');
});

test('a rejected key is told apart from having no domains', function (): void {
    /*
     * Resend answers a bad key with 400 "API key is invalid", not 401. Reading
     * only `data` reported "no verified sending domain" for what is actually a
     * rejected credential — two very different fixes.
     */
    config()->set('mail.default', 'resend');
    config()->set('services.resend.key', 're_bogus');

    Http::fake([
        'api.resend.com/domains' => Http::response(
            ['statusCode' => 400, 'message' => 'API key is invalid', 'name' => 'validation_error'],
            400,
        ),
    ]);

    expect(doctorOutput())
        ->toContain('API key is invalid')
        ->not->toContain('No verified sending domain');
});

test('an empty key is named before anything is attempted', function (): void {
    config()->set('mail.default', 'resend');
    config()->set('services.resend.key', '');

    Http::fake();

    expect(doctorOutput())->toContain('RESEND_KEY is empty');

    Http::assertNothingSent();
});

test('being unable to reach resend is not reported as misconfiguration', function (): void {
    config()->set('mail.default', 'resend');
    config()->set('services.resend.key', 're_test_key');

    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    // Offline is not a fault to chase; saying so would send someone editing
    // config that is already correct.
    expect(doctorOutput())
        ->toContain('could not be checked')
        ->not->toContain('not verified');
});

test('it never prints the SMTP password', function (): void {
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.username', 'postmaster@example.test');
    config()->set('mail.mailers.smtp.password', 'hunter2-should-never-appear');

    $output = doctorOutput();

    expect($output)->toContain('credentials       present')
        ->and($output)->not->toContain('hunter2')
        ->and($output)->not->toContain('postmaster@example.test');
});

test('--send refuses an address with no account behind it', function (): void {
    // Verification mail is addressed to a user; there is nothing to send to a
    // stranger, and pretending otherwise would report a false success.
    expect(doctorOutput(['--send' => 'nobody@example.test']))
        ->toContain('No account with the address');
});
