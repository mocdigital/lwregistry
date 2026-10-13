<?php

declare(strict_types=1);

use App\Support\AdminPages;

/*
 * Every admin page has a way in.
 *
 * The sidebar is a hand-written Blade file, not a loop over AdminPages — so
 * registering a page, giving it a route and a capability leaves it reachable
 * only by typing the URL. That is exactly how the advertising inquiries and
 * the subscriber list shipped: complete, permissioned, and invisible.
 *
 * This is a coverage check rather than a rendering test: it reads the Blade
 * source, so it fails at the moment the page is added rather than waiting for
 * somebody with the right role to look at the right section.
 */

test('every registered admin page is linked from the sidebar', function (): void {
    $sidebar = file_get_contents(resource_path('views/admin/partials/sidebar.blade.php'));

    $linked = [];
    preg_match_all('/AdminPages::([A-Z_]+)/', (string) $sidebar, $matches);
    $linked = array_unique($matches[1]);

    $reflection = new ReflectionClass(AdminPages::class);
    $definitions = AdminPages::all();

    $missing = [];

    foreach ($reflection->getConstants() as $name => $value) {
        if ( ! array_key_exists($value, $definitions)) {
            continue;
        }

        /*
         * A detail page has no `route` of its own — it is reached from the
         * listing above it with an id, and a sidebar entry for "Order Details"
         * with no order to show would be a dead link. Those are registered so
         * the breadcrumb and the capability check can find them, not so they
         * can be navigated to directly.
         */
        if (null === ($definitions[$value]['route'] ?? null)) {
            continue;
        }

        if ( ! in_array($name, $linked, true)) {
            $missing[$name] = $value;
        }
    }

    expect($missing)->toBe(
        [],
        'These admin pages exist but nothing in the sidebar links to them: '
            . implode(', ', array_keys($missing))
            . '. Add a nav-link in resources/views/admin/partials/sidebar.blade.php.',
    );
});

test('every sidebar link points at a route that exists', function (): void {
    $sidebar = file_get_contents(resource_path('views/admin/partials/sidebar.blade.php'));

    preg_match_all("/route\('(admin\.[^']+)'\)/", (string) $sidebar, $matches);

    $broken = array_values(array_filter(
        array_unique($matches[1]),
        fn (string $name): bool => ! app('router')->has($name),
    ));

    expect($broken)->toBe([], 'Sidebar links to routes that do not exist: ' . implode(', ', $broken));
});
