<?php

declare(strict_types=1);

/*
 * Every route name the code calls actually exists.
 *
 * `AccountCompletion` called `route('settings.profile')`. The GET route for
 * /settings/profile is named `profile.edit`; only the PATCH is
 * `settings.profile.update`, which looks close enough to be believed. It threw
 * a RouteNotFoundException — a 500 on the settings page itself — for exactly
 * the members that checklist exists for: the ones whose profile is incomplete.
 * A complete profile skipped the call and hid it.
 *
 * Nothing catches this otherwise. It is not a syntax error, PHPStan does not
 * resolve route names, and a test only finds it by exercising the one branch
 * that builds the URL. Scanning the source is cheaper and catches all of them.
 */

/**
 * Registered only in some environments, so absent under `testing`.
 *
 * Kept as a named list rather than a pattern: an exception should be something
 * somebody decided, not something a regex swallowed.
 */
const ENV_GATED_ROUTES = ['local.sqlite-browser'];

test('every literal route() name in the codebase resolves', function (): void {
    $routes = app('router')->getRoutes();
    $missing = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ('php' !== $file->getExtension()) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        /*
         * The route() HELPER only. `$notifiable->route('mail')` picks a
         * notification channel and `$request->route('uuid')` reads a path
         * parameter — both are methods that happen to share the name, and
         * neither takes a route name.
         *
         * Literal single-quoted names only: one built from a variable cannot
         * be checked here, and guessing would produce failures nobody can act
         * on.
         */
        preg_match_all("/(?<![>:\\w])route\\(\\s*'([a-z0-9_.-]+)'/i", $source, $matches);

        foreach ($matches[1] as $name) {
            if (in_array($name, ENV_GATED_ROUTES, true)) {
                continue;
            }

            if (null === $routes->getByName($name)) {
                $missing[$name][] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }
    }

    expect($missing)->toBe([], 'route() names with no matching route: ' . implode('; ', array_map(
        fn (string $name, array $files): string => $name . ' (' . implode(', ', array_unique($files)) . ')',
        array_keys($missing),
        $missing,
    )));
});

test('every literal route() name in the Blade views resolves', function (): void {
    $routes = app('router')->getRoutes();
    $missing = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ( ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        preg_match_all("/(?<![>:\\w])route\\(\\s*'([a-z0-9_.-]+)'/i", $source, $matches);

        foreach ($matches[1] as $name) {
            if (in_array($name, ENV_GATED_ROUTES, true)) {
                continue;
            }

            if (null === $routes->getByName($name)) {
                $missing[$name][] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }
    }

    expect($missing)->toBe([], 'route() names with no matching route: ' . implode('; ', array_map(
        fn (string $name, array $files): string => $name . ' (' . implode(', ', array_unique($files)) . ')',
        array_keys($missing),
        $missing,
    )));
});
