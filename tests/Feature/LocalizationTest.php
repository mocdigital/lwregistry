<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Locales;
use App\Support\VisitorCountry;

afterEach(fn () => VisitorCountry::force(null));

/*
 * Interface language.
 *
 * The site had no localisation at all: every string was an English literal in
 * a Vue file. These cover the parts that decide which language a reader gets,
 * which is where the surprises live — a saved choice must beat a browser
 * header, and a guest's choice must survive without a database row.
 */

test('the selector offers every configured locale, named in its own language', function (): void {
    $codes = collect(Locales::forSelector())->pluck('code');

    expect($codes->all())->toBe(['en', 'fr', 'es'])
        // A French speaker scans for "Français", not "French".
        ->and(collect(Locales::forSelector())->firstWhere('code', 'fr')['native'])->toBe('Français')
        ->and(collect(Locales::forSelector())->firstWhere('code', 'es')['native'])->toBe('Español');
});

test('a guest can switch language and it sticks across requests', function (): void {
    $this->post('/locale', ['locale' => 'fr'])->assertRedirect();

    expect(session(Locales::SESSION_KEY))->toBe('fr');

    // The next page is served in French without any account involved.
    $this->get('/')->assertInertia(
        fn ($page) => expect($page->toArray()['props']['i18n']['locale'])->toBe('fr'),
    );
});

test('a member switching language has it saved to their account', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->post('/locale', ['locale' => 'es'])->assertRedirect();

    expect($user->refresh()->locale)->toBe('es');
});

test('an unsupported locale is refused', function (): void {
    $this->post('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');

    expect(session(Locales::SESSION_KEY))->not->toBe('de');
});

test("a member's saved choice beats their browser's Accept-Language", function (): void {
    $user = User::factory()->create(['email_verified_at' => now(), 'locale' => 'fr']);

    /*
     * The case this protects: a French speaker on a borrowed, English-configured
     * laptop. They told us once, deliberately; a header they never set should
     * not override it.
     */
    $this->actingAs($user)
        ->withHeader('Accept-Language', 'en-GB,en;q=0.9')
        ->get('/')
        ->assertInertia(fn ($page) => expect($page->toArray()['props']['i18n']['locale'])->toBe('fr'));
});

test('a first-time visitor gets their browser language when it is one we speak', function (): void {
    // Regional tags match on the primary subtag, so fr-CA lands on French
    // rather than falling through to English.
    $this->withHeader('Accept-Language', 'fr-CA,fr;q=0.9,en;q=0.5')
        ->get('/')
        ->assertInertia(fn ($page) => expect($page->toArray()['props']['i18n']['locale'])->toBe('fr'));
});

test('a browser asking for a language we do not speak falls back to english', function (): void {
    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
        ->get('/')
        ->assertInertia(fn ($page) => expect($page->toArray()['props']['i18n']['locale'])->toBe('en'));
});

/*
 * Where the visitor IS, for a first visit. A browser nearly always lists
 * English, usually as the factory setting, so English in Accept-Language is
 * not treated as a choice: the country decides. French or Spanish in the
 * header is a real signal and still wins.
 */
test('a first-time visitor in a french-speaking country gets french despite an english browser', function (): void {
    App\Support\VisitorCountry::force('SN');

    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('/')
        ->assertInertia(fn ($page) => expect($page->toArray()['props']['i18n']['locale'])->toBe('fr'));
});

test('a first-time visitor in spain gets spanish', function (): void {
    App\Support\VisitorCountry::force('ES');

    $this->get('/')
        ->assertInertia(fn ($page) => expect($page->toArray()['props']['i18n']['locale'])->toBe('es'));
});

test('a browser asking for spanish beats a french location', function (): void {
    App\Support\VisitorCountry::force('FR');

    $this->withHeader('Accept-Language', 'es-MX,es;q=0.9,en;q=0.5')
        ->get('/')
        ->assertInertia(fn ($page) => expect($page->toArray()['props']['i18n']['locale'])->toBe('es'));
});

test('a country we have no language for leaves the visitor in english', function (): void {
    App\Support\VisitorCountry::force('NG');

    $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')
        ->get('/')
        ->assertInertia(fn ($page) => expect($page->toArray()['props']['i18n']['locale'])->toBe('en'));
});

test('a language the visitor chose beats their location', function (): void {
    App\Support\VisitorCountry::force('FR');

    $this->post('/locale', ['locale' => 'en']);

    $this->get('/')
        ->assertInertia(fn ($page) => expect($page->toArray()['props']['i18n']['locale'])->toBe('en'));
});

test('the geoip update downloads the current edition and writes it into place', function (): void {
    $path = sys_get_temp_dir() . '/geoip-test-' . uniqid() . '/dbip.mmdb';
    config()->set('locales.geoip.database', $path);

    $month = now()->format('Y-m');
    Illuminate\Support\Facades\Http::fake([
        "download.db-ip.com/free/dbip-country-lite-{$month}.mmdb.gz" => Illuminate\Support\Facades\Http::response(gzencode('fake-mmdb-bytes'), 200),
    ]);

    $this->artisan('geoip:update')->assertSuccessful();

    expect(file_get_contents($path))->toBe('fake-mmdb-bytes');

    unlink($path);
    rmdir(dirname($path));
});

test('the geoip update falls back to last month when this month is not out yet', function (): void {
    $path = sys_get_temp_dir() . '/geoip-test-' . uniqid() . '/dbip.mmdb';
    config()->set('locales.geoip.database', $path);

    $this_month = now()->format('Y-m');
    $last_month = now()->subMonthNoOverflow()->format('Y-m');
    Illuminate\Support\Facades\Http::fake([
        "download.db-ip.com/free/dbip-country-lite-{$this_month}.mmdb.gz" => Illuminate\Support\Facades\Http::response('', 404),
        "download.db-ip.com/free/dbip-country-lite-{$last_month}.mmdb.gz" => Illuminate\Support\Facades\Http::response(gzencode('previous-edition'), 200),
    ]);

    $this->artisan('geoip:update')->assertSuccessful();

    expect(file_get_contents($path))->toBe('previous-edition');

    unlink($path);
    rmdir(dirname($path));
});

test('translations ship with the page, merged over english', function (): void {
    $this->post('/locale', ['locale' => 'fr']);

    $this->get('/')->assertInertia(function ($page): void {
        $translations = $page->toArray()['props']['i18n']['translations'];

        expect($translations['nav.directory'])->toBe('Annuaire')
            ->and($translations['chat.see_translation'])->toBe('Voir la traduction')
            /*
             * Merged over English so a key nobody has translated yet renders as
             * English rather than as its own dotted key — a reader seeing
             * "directory.title" is worse than one seeing "Directory".
             */
            ->and($translations)->toHaveKey('nav.home');
    });
});

test('translation coverage does not go backwards', function (): void {
    $read = fn (string $code): array => json_decode(
        (string) file_get_contents(lang_path($code . '.json')),
        true,
    );

    $english = $read('en');

    /*
     * A ratchet, not a completeness check.
     *
     * A missing key is not a bug at runtime — the server merges English under
     * the active locale, so an untranslated string renders in English rather
     * than as a dotted key. What WOULD be a bug is coverage going backwards:
     * someone adding English strings and quietly leaving the other languages
     * behind, or deleting translations.
     *
     * Raise these floors as translation lands. They are the numbers to beat,
     * not the target — the target is 100%.
     */
    $floors = ['fr' => 1985, 'es' => 1985];

    foreach ($floors as $code => $floor) {
        $translated = count(array_intersect_key($read($code), $english));
        $percent = (int) round($translated / count($english) * 100);

        expect($translated)->toBeGreaterThanOrEqual(
            $floor,
            "{$code} coverage fell to {$translated}/" . count($english)
                . " ({$percent}%), below the recorded floor of {$floor}.",
        );
    }
});

test('no translation file carries a key english does not have', function (): void {
    $read = fn (string $code): array => json_decode(
        (string) file_get_contents(lang_path($code . '.json')),
        true,
    );

    $english = $read('en');

    foreach (['fr', 'es'] as $code) {
        // An orphan is dead weight: nothing can ever render it, and it usually
        // means a key was renamed on one side only.
        $orphans = array_diff_key($read($code), $english);

        expect($orphans)->toBe([], "Orphaned {$code} keys: " . implode(', ', array_keys($orphans)));
    }
});

test('every translation key the interface calls exists in english', function (): void {
    /*
     * The bug this catches: a key that resolves to nothing renders as the key
     * itself, so a member sees "profile_dashboard.quick_portal_actions" on the
     * page. It happened once, between extracting strings and merging them into
     * en.json, and nothing in the suite would have noticed.
     *
     * English is the merge base for every locale, so a key present here can
     * never render as a raw slug in any language.
     */
    $english = json_decode((string) file_get_contents(lang_path('en.json')), true);

    $called = [];

    foreach (Symfony\Component\Finder\Finder::create()
        ->files()
        ->in(resource_path('js/spa'))
        ->name('*.vue') as $file) {
        // (?<![A-Za-z0-9_]) so the "t(" inside reset('…') is not a match.
        preg_match_all(
            "/(?<![A-Za-z0-9_])t\('([a-z0-9_.]+)'/",
            $file->getContents(),
            $matches,
        );

        foreach ($matches[1] as $key) {
            $called[$key] = $file->getRelativePathname();
        }
    }

    expect($called)->not->toBeEmpty();

    $missing = array_diff_key($called, $english);

    expect($missing)->toBe([], 'Keys with no English string: ' . implode(', ', array_map(
        static fn (string $k, string $f): string => "{$k} ({$f})",
        array_keys($missing),
        $missing,
    )));
});

test('every translation key the SERVER calls exists in english', function (): void {
    /*
     * The same bug, on the other side of the wire — and worse.
     *
     * The Vue `t()` humanises an unresolved key into readable prose, so a
     * missing one degrades to "Quick portal actions". PHP's `__()` returns the
     * KEY, so a missing one puts "opportunities.deadline" on the page verbatim.
     * That shipped: the Live Opportunities rail printed exactly that, because
     * the test above only ever scanned .vue files.
     */
    $english = json_decode((string) file_get_contents(lang_path('en.json')), true);

    $called = [];

    foreach (Symfony\Component\Finder\Finder::create()
        ->files()
        ->in(app_path())
        ->name('*.php') as $file) {
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $file->getContents());

        /*
         * `__(` and `trans_choice(`, not preceded by a word character or an
         * arrow — `$this->__(` and the like are different methods.
         *
         * A key must contain a dot: `__('Some sentence')` is Laravel's other
         * form, where the string IS the key and a missing translation renders
         * the English rather than a slug.
         */
        preg_match_all(
            "/(?<![\\w>])(?:__|trans_choice)\\(\\s*'([a-z0-9_.]+\\.[a-z0-9_.]+)'/",
            $source,
            $matches,
        );

        foreach ($matches[1] as $key) {
            /*
             * A key built by concatenation — `__('data.status.' . $state)` —
             * matches up to the dot. There is nothing to check: the suffix is
             * only known at runtime.
             */
            if (str_ends_with($key, '.')) {
                continue;
            }

            $called[$key] = $file->getRelativePathname();
        }
    }

    expect($called)->not->toBeEmpty();

    $missing = array_diff_key($called, $english);

    expect($missing)->toBe([], 'Server keys with no English string: ' . implode(', ', array_map(
        static fn (string $k, string $f): string => "{$k} ({$f})",
        array_keys($missing),
        $missing,
    )));
});
