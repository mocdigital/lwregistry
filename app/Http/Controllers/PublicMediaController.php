<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PublicMediaController extends Controller
{
    public function __invoke(Request $request, string $path): StreamedResponse
    {
        try {
            abort_unless(Storage::disk('public')->exists($path), 404);

            return Storage::disk('public')->response(
                $path,
                basename($path),
                [
                    'Cache-Control' => 'public, max-age=604800',
                    'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
                ],
                'inline',
            );
        } catch (PathTraversalDetected) {
            abort(404);
        }
    }
}
