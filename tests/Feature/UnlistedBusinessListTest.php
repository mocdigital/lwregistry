<?php

declare(strict_types=1);

use App\Models\Sbo;
use Illuminate\Support\Str;

/*
 * /rlist/businesses — every registered business, behind one password, at a URL
 * nothing links to.
 *
 * The point of the page is that it is unreachable except by typing the address
 * and knowing the password, so these cover the two halves: the contents never
 * appear without the password, and the page never invites a crawler.
 */

function listedBusiness(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Kingdom Freight ' . Str::random(5);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'category' => 'Logistics',
        'primary_contact_email' => 'hello@kingdomfreight.test',
        'phone' => '+2348031234567',
        'website' => 'kingdomfreight.test',
        'status' => 'active',
        'is_active' => true,
        'verification_status' => 'pending',
    ], $overrides));
}

/** The password the page is configured with, for these tests only. */
function rlistPassword(): string
{
    $password = 'test-rlist-password';

    config(['rlist.password_hash' => password_hash($password, PASSWORD_BCRYPT)]);

    return $password;
}

test('without the password the page shows nothing but the prompt', function (): void {
    listedBusiness(['name' => 'Secret Holdings Ltd']);
    rlistPassword();

    $this->get('/rlist/businesses')
        ->assertOk()
        ->assertSee('Password')
        // Not one field of the data leaks onto the gate.
        ->assertDontSee('Secret Holdings Ltd')
        ->assertDontSee('hello@kingdomfreight.test')
        ->assertDontSee('+2348031234567');
});

test('a wrong password does not unlock it', function (): void {
    listedBusiness(['name' => 'Secret Holdings Ltd']);
    rlistPassword();

    $this->post('/rlist/businesses/unlock', ['password' => 'not the password'])
        ->assertSessionHasErrors('password');

    $this->get('/rlist/businesses')->assertDontSee('Secret Holdings Ltd');
});

test('the right password shows every business with its contact details', function (): void {
    listedBusiness(['name' => 'Alpha Works']);
    listedBusiness([
        'name' => 'Beta Trading',
        'entity_type' => 'COB',
        'primary_contact_email' => 'beta@example.test',
        'phone' => '+2349000000000',
        'website' => null,
    ]);

    $this->post('/rlist/businesses/unlock', ['password' => rlistPassword()])
        ->assertRedirect(route('rlist.businesses'));

    $this->get('/rlist/businesses')
        ->assertOk()
        ->assertSee('Alpha Works')
        ->assertSee('Beta Trading')
        ->assertSee('Logistics')
        ->assertSee('hello@kingdomfreight.test')
        ->assertSee('+2349000000000')
        ->assertSee('kingdomfreight.test')
        // A business with no website still appears, with the gap shown.
        ->assertSee('COB');
});

test('a surrounding space in the pasted password is not a wrong password', function (): void {
    listedBusiness(['name' => 'Alpha Works']);

    $this->post('/rlist/businesses/unlock', ['password' => '  ' . rlistPassword() . ' '])
        ->assertSessionHasNoErrors();

    $this->get('/rlist/businesses')->assertSee('Alpha Works');
});

test('one space or two inside the password both work', function (): void {
    listedBusiness(['name' => 'Alpha Works']);

    // The real password has a space in it, so "did I type one or two?" must not
    // be indistinguishable from getting it wrong.
    $password = 'two word password';
    config(['rlist.password_hash' => password_hash($password, PASSWORD_BCRYPT)]);

    $this->post('/rlist/businesses/unlock', ['password' => 'two  word   password'])
        ->assertSessionHasNoErrors();

    $this->get('/rlist/businesses')->assertSee('Alpha Works');
});

test('only whitespace is forgiven — the characters must still be right', function (): void {
    listedBusiness(['name' => 'Alpha Works']);

    config(['rlist.password_hash' => password_hash('two word password', PASSWORD_BCRYPT)]);

    $this->post('/rlist/businesses/unlock', ['password' => 'twowordpassword'])
        ->assertSessionHasErrors('password');

    $this->get('/rlist/businesses')->assertDontSee('Alpha Works');
});

test('the page tells every crawler to stay out, locked or unlocked', function (): void {
    listedBusiness();
    rlistPassword();

    $gate = $this->get('/rlist/businesses');
    expect($gate->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($gate->headers->get('Referrer-Policy'))->toBe('no-referrer');
    $gate->assertSee('noindex, nofollow', false);

    $this->post('/rlist/businesses/unlock', ['password' => rlistPassword()]);

    $unlocked = $this->get('/rlist/businesses');
    expect($unlocked->headers->get('X-Robots-Tag'))->toContain('noindex')
        // Never cached, so it cannot be read back after the session locks.
        ->and($unlocked->headers->get('Cache-Control'))->toContain('no-store');
    $unlocked->assertSee('noindex, nofollow', false);
});

test('locking it again hides the list', function (): void {
    listedBusiness(['name' => 'Alpha Works']);

    $this->post('/rlist/businesses/unlock', ['password' => rlistPassword()]);
    $this->get('/rlist/businesses')->assertSee('Alpha Works');

    $this->post('/rlist/businesses/lock')->assertRedirect(route('rlist.businesses'));
    $this->get('/rlist/businesses')->assertDontSee('Alpha Works');
});

test('robots.txt does not name the path, which would publish it', function (): void {
    expect(file_get_contents(public_path('robots.txt')))->not->toContain('rlist');
});
