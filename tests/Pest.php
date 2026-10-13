<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
|--------------------------------------------------------------------------
| Inertia helpers
|--------------------------------------------------------------------------
|
| Most screens render through Inertia now, so the page body is a JSON payload
| in a data attribute rather than markup. `assertSee('Some Business')` against
| that is not an assertion about the screen: a name can "appear" because it sat
| in the props of a component that never rendered it, and can fail to appear
| despite being in the result set. These read the payload the screen is
| actually handed.
|
*/

/** One prop off an Inertia response, by dot path. */
function inertiaProp($response, string $key): mixed
{
    $value = null;

    $response->assertInertia(function ($page) use (&$value, $key): void {
        $value = data_get($page->toArray()['props'], $key);
    });

    return $value;
}

/** A dot-path prop collected — e.g. every `name` under `listings.data`. */
function inertiaPluck($response, string $key, string $field): Illuminate\Support\Collection
{
    return collect(inertiaProp($response, $key) ?? [])->pluck($field);
}

/** The names the directory returned. */
function directoryNames($response): Illuminate\Support\Collection
{
    return inertiaPluck($response, 'listings.data', 'name');
}

/**
 * A signed-in paid member.
 *
 * The registry is members-only and paid, so almost every screen needs one —
 * `SboWorkspaceAccess::requiresPayment()` gates anyone without a confirmed
 * registration fee, including a user who owns no listing at all.
 */
function paidMember(array $userOverrides = [], array $sboOverrides = []): App\Models\User
{
    $user = App\Models\User::factory()->create(array_merge([
        'role' => App\Enums\UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ], $userOverrides));

    $name = $sboOverrides['name'] ?? 'Member SBO ' . Illuminate\Support\Str::random(6);

    App\Models\Sbo::create(array_merge([
        'name' => $name,
        'slug' => Illuminate\Support\Str::slug($name) . '-' . Illuminate\Support\Str::random(5),
        'created_by' => $user->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ], $sboOverrides));

    return $user->fresh();
}
