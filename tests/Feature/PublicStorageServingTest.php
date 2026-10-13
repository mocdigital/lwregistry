<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('generates public upload URLs under media instead of blocked storage paths', function (): void {
    // Store a fake profile photo the way ProfileModal does.
    $path = UploadedFile::fake()->image('avatar.jpg')->store('profile-photos', 'public');

    // The URL the app generates for the avatar.
    $url = Storage::disk('public')->url($path);
    $relative = parse_url($url, PHP_URL_PATH); // e.g. /media/profile-photos/xxxx.jpg

    expect($relative)->toStartWith('/media/')
        ->not->toStartWith('/storage/');

    // Hitting it must return the image, not a 404/broken response — proving the
    // public disk is web-served (serve => true) independent of the symlink.
    $this->get($relative)
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');
});

it('404s for a missing public file rather than erroring', function (): void {
    $this->get('/media/profile-photos/does-not-exist.jpg')->assertNotFound();
});

it('does not expose the private local disk over media', function (): void {
    // A file on the private `local` disk must NOT be reachable via /storage.
    Storage::disk('local')->put('secret.txt', 'top secret');

    $this->get('/media/secret.txt')->assertNotFound();
});
