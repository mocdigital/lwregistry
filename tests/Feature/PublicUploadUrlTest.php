<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

/*
 * Public uploads are addressed through the disk, never by writing "/storage/".
 *
 * Live nginx denies /storage/* outright — the rule is aimed at the source tree
 * (app, config, routes, vendor…) and catches the conventional upload path with
 * it. So a hardcoded /storage/ link is a 403 in production while working
 * perfectly on a workstation, which is the worst way for a bug to behave.
 *
 * Three of them shipped: the RFQ document download and the bid attachment on
 * the dashboard, and the proposal link on the SBO RFQ page. All three were
 * dead links on live. The disk's url() points at the /media route, which is
 * the endpoint that actually serves uploads.
 */

/**
 * Comment markers are excluded: the fix itself has to be able to explain what
 * it is avoiding and why, or the next person reinstates the bug.
 */
function uploadUrlOffenders(string $directory): array
{
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ( ! in_array($file->getExtension(), ['php', 'vue', 'js', 'ts'], true)) {
            continue;
        }

        foreach (file($file->getPathname()) ?: [] as $number => $line) {
            $trimmed = mb_ltrim($line);

            // Comment lines in every syntax these files use.
            if (preg_match('#^(//|\*|/\*|\{\{--|<!--|\#)#', $trimmed)) {
                continue;
            }

            // A literal /storage/ path being built into a URL, in any quoting.
            if (preg_match('#(asset\(\s*[\'"]storage/|[\'"`]/storage/)#', $line)) {
                $offenders[] = str_replace(base_path() . '/', '', $file->getPathname())
                    . ':' . ($number + 1);
            }
        }
    }

    return $offenders;
}

test('nothing builds a public upload URL by hand', function (): void {
    $offenders = array_merge(
        uploadUrlOffenders(resource_path('views')),
        uploadUrlOffenders(app_path()),
        uploadUrlOffenders(resource_path('js')),
    );

    expect($offenders)->toBe([], 'hardcoded /storage/ URLs: ' . implode(', ', $offenders));
});

test('the public disk addresses uploads through the media route', function (): void {
    // The whole reason the hardcoded paths were wrong. If this ever points at
    // /storage again, every upload on live 403s and the guard above is moot.
    expect(Storage::disk('public')->url('profile-photos/example.png'))
        ->toContain('/media/profile-photos/example.png')
        ->not->toContain('/storage/');
});
